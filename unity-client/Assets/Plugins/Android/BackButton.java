package com.moodswings;

import android.app.Activity;
import android.os.Build;
import android.view.KeyEvent;
import android.view.Window;
import android.window.OnBackInvokedCallback;
import android.window.OnBackInvokedDispatcher;

import java.lang.reflect.InvocationHandler;
import java.lang.reflect.Method;
import java.lang.reflect.Proxy;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Catches the system Back button (or gesture) so the app can use it to go back a screen instead of closing. Unity's
 * Input System has no Back key on a phone (no keyboard), so the C# side (AndroidBack) polls consume() once a frame.
 *
 * Android delivers Back one of two ways, depending on the version and the app's settings, so both are covered: as an
 * OnBackInvokedCallback (Android 13+ with predictive back on, the default for an app targeting Android 15 or later), and as
 * a plain key event (everything else), seen by wrapping the window's callback. Only one of them fires for a given press;
 * presses closer together than a blink are counted once in case that ever stops being so.
 */
public final class BackButton {
    private static final AtomicInteger presses = new AtomicInteger();
    private static volatile long lastPress;
    private static boolean registered;

    private BackButton() {
    }

    /** Starts catching Back for this activity (once). */
    public static void register(final Activity activity) {
        if (registered) {
            return;
        }
        registered = true;
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    if (Build.VERSION.SDK_INT >= 33) {
                        activity.getOnBackInvokedDispatcher().registerOnBackInvokedCallback(
                                OnBackInvokedDispatcher.PRIORITY_DEFAULT,
                                new OnBackInvokedCallback() {
                                    @Override
                                    public void onBackInvoked() {
                                        pressed();
                                    }
                                });
                    }
                    wrapWindowCallback(activity.getWindow());
                } catch (Throwable ignored) {
                    // Back then does what the system does by default.
                }
            }
        });
    }

    // The window's key events pass through a proxy that takes the Back key's release and hands everything else on.
    private static void wrapWindowCallback(Window window) {
        final Window.Callback original = window.getCallback();
        if (original == null) {
            return;
        }
        Window.Callback wrapper = (Window.Callback) Proxy.newProxyInstance(
                Window.Callback.class.getClassLoader(),
                new Class<?>[] {Window.Callback.class},
                new InvocationHandler() {
                    @Override
                    public Object invoke(Object proxy, Method method, Object[] args) throws Throwable {
                        if ("dispatchKeyEvent".equals(method.getName()) && args != null && args.length == 1 && args[0] instanceof KeyEvent) {
                            KeyEvent event = (KeyEvent) args[0];
                            if (event.getKeyCode() == KeyEvent.KEYCODE_BACK) {
                                if (event.getAction() == KeyEvent.ACTION_UP && !event.isCanceled()) {
                                    pressed();
                                }
                                return Boolean.TRUE;
                            }
                        }
                        try {
                            return method.invoke(original, args);
                        } catch (java.lang.reflect.InvocationTargetException e) {
                            throw e.getCause() != null ? e.getCause() : e;
                        }
                    }
                });
        window.setCallback(wrapper);
    }

    private static void pressed() {
        long now = System.currentTimeMillis();
        if (now - lastPress < 150) {
            return;
        }
        lastPress = now;
        presses.incrementAndGet();
    }

    /** True once for each Back press that has not been handled yet. */
    public static boolean consume() {
        while (true) {
            int count = presses.get();
            if (count == 0) {
                return false;
            }
            if (presses.compareAndSet(count, count - 1)) {
                return true;
            }
        }
    }

    /** Puts the app in the background, as Back does at the top of an app, rather than closing it. */
    public static void leave(final Activity activity) {
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                activity.moveTaskToBack(true);
            }
        });
    }
}
