package com.moodswings;

import android.app.Activity;
import android.app.Fragment;
import android.content.ContentResolver;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.DocumentsContract;
import android.provider.OpenableColumns;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.Locale;

/**
 * Lets the player choose a folder of card images, or a zip of them, with the system's document picker (no storage
 * permission needed), for the art packs in Settings. An app can't read a folder it was given in place, so what was
 * chosen is copied into the app's cache and the C# side (AndroidFilePicker) is handed that copy's path; it is polled
 * (done/cancelled/path/name/error) rather than called back, like MoodPush.
 *
 * Only the files directly in the chosen folder are copied, and only PNG and JPG ones within the size limits.
 */
public final class ArtPicker {
    private static final int REQUEST = 7311;
    private static final long MAX_FILE_BYTES = 12L * 1024 * 1024;
    private static final long MAX_TOTAL_BYTES = 400L * 1024 * 1024;
    private static final int MAX_FILES = 600;

    private static volatile boolean done;
    private static volatile boolean cancelled;
    private static volatile String path;
    private static volatile String name;
    private static volatile String error;

    private ArtPicker() {
    }

    /** Shows the picker for a folder (folder = true) or a zip file. */
    public static void pick(final Activity activity, final boolean folder, final String cacheDir) {
        done = false;
        cancelled = false;
        path = null;
        name = null;
        error = null;
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                try {
                    PickerFragment fragment = new PickerFragment();
                    Bundle arguments = new Bundle();
                    arguments.putBoolean("folder", folder);
                    arguments.putString("cacheDir", cacheDir);
                    fragment.setArguments(arguments);
                    activity.getFragmentManager().beginTransaction().add(fragment, "mood-art-picker").commitAllowingStateLoss();
                } catch (Exception e) {
                    error = e.toString();
                    done = true;
                }
            }
        });
    }

    public static boolean done() {
        return done;
    }

    public static boolean cancelled() {
        return cancelled;
    }

    public static String path() {
        return path;
    }

    public static String name() {
        return name;
    }

    public static String error() {
        return error;
    }

    /** A headless fragment: it exists to receive the picker's result, which only an Activity or Fragment can. */
    @SuppressWarnings("deprecation")
    public static final class PickerFragment extends Fragment {
        @Override
        public void onCreate(Bundle state) {
            super.onCreate(state);
            if (state != null) {
                // Recreated after the app was killed while the picker was up: nothing to wait for.
                cancelled = true;
                done = true;
                return;
            }

            boolean folder = getArguments().getBoolean("folder");
            Intent intent = new Intent(folder ? Intent.ACTION_OPEN_DOCUMENT_TREE : Intent.ACTION_OPEN_DOCUMENT);
            if (!folder) {
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                intent.setType("*/*");
                intent.putExtra(Intent.EXTRA_MIME_TYPES, new String[] {"application/zip", "application/x-zip-compressed", "application/octet-stream"});
            }
            try {
                startActivityForResult(intent, REQUEST);
            } catch (Exception e) {
                error = e.toString();
                done = true;
                finish();
            }
        }

        @Override
        public void onActivityResult(final int requestCode, final int resultCode, final Intent data) {
            if (requestCode != REQUEST) {
                return;
            }

            final boolean folder = getArguments().getBoolean("folder");
            final String cacheDir = getArguments().getString("cacheDir");
            final ContentResolver resolver = getActivity().getContentResolver();
            if (resultCode != Activity.RESULT_OK || data == null || data.getData() == null) {
                cancelled = true;
                done = true;
                finish();
                return;
            }

            final Uri uri = data.getData();
            // Copying can take a while for a big pack: not on the UI thread.
            new Thread(new Runnable() {
                @Override
                public void run() {
                    try {
                        clearOldCopies(cacheDir);
                        long stamp = System.currentTimeMillis();
                        if (folder) {
                            File target = new File(cacheDir, "art-pick-" + stamp);
                            target.mkdirs();
                            name = displayNameOfTree(resolver, uri);
                            copyTree(resolver, uri, target);
                            path = target.getAbsolutePath();
                        } else {
                            File target = new File(cacheDir, "art-pick-" + stamp + ".zip");
                            name = displayName(resolver, uri);
                            copy(resolver.openInputStream(uri), target, MAX_TOTAL_BYTES);
                            path = target.getAbsolutePath();
                        }
                    } catch (Exception e) {
                        error = e.toString();
                    }
                    done = true;
                }
            }).start();
            finish();
        }

        private void finish() {
            try {
                getFragmentManager().beginTransaction().remove(this).commitAllowingStateLoss();
            } catch (Exception ignored) {
                // Already gone.
            }
        }
    }

    private static void clearOldCopies(String cacheDir) {
        File[] old = new File(cacheDir).listFiles();
        if (old == null) {
            return;
        }
        for (File file : old) {
            if (file.getName().startsWith("art-pick-")) {
                deleteRecursively(file);
            }
        }
    }

    private static void deleteRecursively(File file) {
        File[] children = file.listFiles();
        if (children != null) {
            for (File child : children) {
                deleteRecursively(child);
            }
        }
        file.delete();
    }

    private static String displayNameOfTree(ContentResolver resolver, Uri tree) {
        try {
            Uri root = DocumentsContract.buildDocumentUriUsingTree(tree, DocumentsContract.getTreeDocumentId(tree));
            Cursor cursor = resolver.query(root, new String[] {DocumentsContract.Document.COLUMN_DISPLAY_NAME}, null, null, null);
            if (cursor != null) {
                try {
                    if (cursor.moveToFirst() && cursor.getString(0) != null) {
                        return cursor.getString(0);
                    }
                } finally {
                    cursor.close();
                }
            }
        } catch (Exception ignored) {
            // Fall through to a plain name.
        }
        return "Art pack";
    }

    private static String displayName(ContentResolver resolver, Uri uri) {
        Cursor cursor = resolver.query(uri, new String[] {OpenableColumns.DISPLAY_NAME}, null, null, null);
        if (cursor != null) {
            try {
                if (cursor.moveToFirst() && cursor.getString(0) != null) {
                    String display = cursor.getString(0);
                    return display.toLowerCase(Locale.ROOT).endsWith(".zip") ? display.substring(0, display.length() - 4) : display;
                }
            } finally {
                cursor.close();
            }
        }
        return "Art pack";
    }

    /** Copies the PNG and JPG files directly inside the chosen folder. */
    private static void copyTree(ContentResolver resolver, Uri tree, File target) throws Exception {
        String treeId = DocumentsContract.getTreeDocumentId(tree);
        Uri children = DocumentsContract.buildChildDocumentsUriUsingTree(tree, treeId);
        Cursor cursor = resolver.query(children, new String[] {
                DocumentsContract.Document.COLUMN_DOCUMENT_ID,
                DocumentsContract.Document.COLUMN_DISPLAY_NAME,
                DocumentsContract.Document.COLUMN_MIME_TYPE,
                DocumentsContract.Document.COLUMN_SIZE}, null, null, null);
        if (cursor == null) {
            return;
        }
        int files = 0;
        long total = 0;
        try {
            while (cursor.moveToNext() && files < MAX_FILES && total < MAX_TOTAL_BYTES) {
                String documentId = cursor.getString(0);
                String fileName = cursor.getString(1);
                String mime = cursor.getString(2);
                long size = cursor.isNull(3) ? 0 : cursor.getLong(3);
                if (fileName == null || DocumentsContract.Document.MIME_TYPE_DIR.equals(mime) || size > MAX_FILE_BYTES) {
                    continue;
                }
                String lower = fileName.toLowerCase(Locale.ROOT);
                if (!(lower.endsWith(".png") || lower.endsWith(".jpg") || lower.endsWith(".jpeg"))) {
                    continue;
                }
                Uri document = DocumentsContract.buildDocumentUriUsingTree(tree, documentId);
                // Only the file's own name is used, never a path.
                File out = new File(target, fileName.replace('/', '_').replace('\\', '_'));
                total += copy(resolver.openInputStream(document), out, MAX_FILE_BYTES);
                files++;
            }
        } finally {
            cursor.close();
        }
    }

    private static long copy(InputStream input, File target, long limit) throws Exception {
        if (input == null) {
            throw new Exception("Couldn't open " + target.getName());
        }
        long written = 0;
        OutputStream output = new FileOutputStream(target);
        try {
            byte[] buffer = new byte[64 * 1024];
            int read;
            while ((read = input.read(buffer)) > 0) {
                written += read;
                if (written > limit) {
                    throw new Exception(target.getName() + " is too big");
                }
                output.write(buffer, 0, read);
            }
        } finally {
            output.close();
            input.close();
        }
        return written;
    }
}
