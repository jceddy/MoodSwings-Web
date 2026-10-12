package com.moodswings;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;

import com.google.android.gms.tasks.OnCompleteListener;
import com.google.android.gms.tasks.Task;
import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import com.google.firebase.messaging.FirebaseMessaging;

/**
 * Push token and tapped-notification access for Firebase Cloud Messaging, called from AndroidPushTokenProvider.cs.
 * Firebase is started from values passed in (the project's google-services.json, read by the C# side) instead of
 * the google-services Gradle plugin, so the Unity-generated Gradle project needs only the messaging dependency.
 *
 * The token is fetched asynchronously; the C# side polls done()/token()/error() rather than passing a callback
 * object across the JNI boundary.
 */
public final class MoodPush {
    private static volatile boolean done;
    private static volatile String token;
    private static volatile String error;

    private MoodPush() {
    }

    /** Starts fetching this install's FCM registration token. */
    public static void start(Context context, String appId, String apiKey, String projectId, String senderId) {
        done = false;
        token = null;
        error = null;
        try {
            if (FirebaseApp.getApps(context).isEmpty()) {
                FirebaseApp.initializeApp(context, new FirebaseOptions.Builder()
                        .setApplicationId(appId)
                        .setApiKey(apiKey)
                        .setProjectId(projectId)
                        .setGcmSenderId(senderId)
                        .build());
            }

            FirebaseMessaging.getInstance().getToken().addOnCompleteListener(new OnCompleteListener<String>() {
                @Override
                public void onComplete(Task<String> task) {
                    if (task.isSuccessful()) {
                        token = task.getResult();
                    } else {
                        Exception failure = task.getException();
                        error = failure != null ? failure.toString() : "token request failed";
                    }
                    done = true;
                }
            });
        } catch (Exception e) {
            error = e.toString();
            done = true;
        }
    }

    public static boolean done() {
        return done;
    }

    public static String token() {
        return token;
    }

    public static String error() {
        return error;
    }

    /**
     * The link of the notification that opened (or brought forward) the app, once: Firebase hands the notification's
     * data to the launched activity as intent extras, and this clears it so the same tap isn't routed twice.
     */
    public static String takeLaunchUrl(Activity activity) {
        Intent intent = activity.getIntent();
        if (intent == null) {
            return null;
        }
        String url = intent.getStringExtra("url");
        if (url != null) {
            intent.removeExtra("url");
        }
        return url;
    }
}
