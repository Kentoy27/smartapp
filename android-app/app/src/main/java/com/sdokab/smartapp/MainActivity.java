package com.sdokab.smartapp;

import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.provider.MediaStore;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.URLUtil;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.Toast;

import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;

import java.io.File;
import java.io.IOException;

public final class MainActivity extends Activity {
    private static final String APP_HOST = "smartapp.sdokab.com";
    private static final String START_URL = "https://smartapp.sdokab.com/smartapp/home";
    private static final long MINIMUM_SPLASH_DURATION_MS = 850;
    private static final int FILE_CHOOSER_REQUEST = 4107;
    private static final int CAMERA_PERMISSION_REQUEST = 4108;
    private static final int DOWNLOAD_PERMISSION_REQUEST = 4109;

    private WebView webView;
    private ValueCallback<Uri[]> fileChooserCallback;
    private PermissionRequest pendingWebPermissionRequest;
    private Uri cameraPhotoUri;
    private File cameraPhotoFile;
    private boolean pendingCameraCapture;
    private PendingDownload pendingDownload;
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private long activityStartedAt;
    private View startupArtwork;
    private Runnable hideArtworkRunnable;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        activityStartedAt = SystemClock.elapsedRealtime();

        getWindow().setStatusBarColor(0xFFF1F5F9);
        getWindow().setNavigationBarColor(0xFFF1F5F9);
        getWindow().getDecorView().setSystemUiVisibility(
                android.view.View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR
                        | android.view.View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
        );

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(0xFFEFF6FF);

        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        ImageView artwork = new ImageView(this);
        artwork.setImageResource(R.drawable.login_brand_splash);
        artwork.setScaleType(ImageView.ScaleType.FIT_CENTER);
        artwork.setPadding(dp(20), dp(20), dp(20), dp(20));
        artwork.setContentDescription("SmartApp - Strategic Monitoring and Resource Tracking");
        startupArtwork = artwork;
        FrameLayout.LayoutParams artworkParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
                Gravity.CENTER
        );
        root.addView(startupArtwork, artworkParams);
        setContentView(root);

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setLoadsImagesAutomatically(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setSupportMultipleWindows(false);
        settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setUserAgentString(settings.getUserAgentString() + " SmartAppAndroid/1.0.3");
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
            settings.setSafeBrowsingEnabled(true);
        }

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageCommitVisible(WebView view, String url) {
                hideStartupArtworkWhenReady();
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                hideStartupArtworkWhenReady();
            }

            @Override
            public void onReceivedError(
                    WebView view,
                    WebResourceRequest request,
                    android.webkit.WebResourceError error
            ) {
                if (request.isForMainFrame()) {
                    hideStartupArtworkWhenReady();
                }
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return openOrKeepUrl(request.getUrl());
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return openOrKeepUrl(Uri.parse(url));
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(
                    WebView view,
                    ValueCallback<Uri[]> filePathCallback,
                    FileChooserParams fileChooserParams
            ) {
                if (fileChooserCallback != null) {
                    finishFileChooser(null);
                    discardCameraPhoto();
                }

                fileChooserCallback = filePathCallback;
                if (fileChooserParams.isCaptureEnabled() && acceptsImage(fileChooserParams.getAcceptTypes())) {
                    if (hasCameraPermission()) {
                        launchCameraCapture();
                    } else {
                        pendingCameraCapture = true;
                        requestPermissions(
                                new String[]{Manifest.permission.CAMERA},
                                CAMERA_PERMISSION_REQUEST
                        );
                    }
                    return true;
                }

                launchFilePicker(fileChooserParams);
                return true;
            }

            @Override
            public void onPermissionRequest(PermissionRequest request) {
                if (!isTrustedAppOrigin(request.getOrigin())) {
                    request.deny();
                    return;
                }

                if (pendingWebPermissionRequest != null) {
                    pendingWebPermissionRequest.deny();
                }

                for (String resource : request.getResources()) {
                    if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                        pendingWebPermissionRequest = request;
                        if (hasCameraPermission()) {
                            grantWebCameraPermission();
                        } else {
                            requestPermissions(
                                    new String[]{Manifest.permission.CAMERA},
                                    CAMERA_PERMISSION_REQUEST
                            );
                        }
                        return;
                    }
                }

                request.deny();
            }

            @Override
            public void onPermissionRequestCanceled(PermissionRequest request) {
                if (pendingWebPermissionRequest == request) {
                    pendingWebPermissionRequest = null;
                }
            }
        });

        webView.setDownloadListener(createDownloadListener());

        if (savedInstanceState == null) {
            webView.loadUrl(START_URL);
        } else {
            webView.restoreState(savedInstanceState);
        }
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void hideStartupArtworkWhenReady() {
        if (startupArtwork == null || hideArtworkRunnable != null) {
            return;
        }

        long elapsed = SystemClock.elapsedRealtime() - activityStartedAt;
        long delay = Math.max(0, MINIMUM_SPLASH_DURATION_MS - elapsed);
        hideArtworkRunnable = () -> {
            View artwork = startupArtwork;
            if (artwork == null) {
                return;
            }

            artwork.animate()
                    .alpha(0f)
                    .setDuration(180)
                    .withEndAction(() -> {
                        if (artwork.getParent() instanceof ViewGroup) {
                            ((ViewGroup) artwork.getParent()).removeView(artwork);
                        }
                        startupArtwork = null;
                    })
                    .start();
        };
        mainHandler.postDelayed(hideArtworkRunnable, delay);
    }

    private boolean openOrKeepUrl(Uri uri) {
        String scheme = uri.getScheme();
        String host = uri.getHost();

        if ("https".equalsIgnoreCase(scheme) && isTrustedWebHost(host)) {
            return false;
        }

        if ("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)) {
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
            } catch (ActivityNotFoundException exception) {
                Toast.makeText(this, "No browser is available to open this link.", Toast.LENGTH_LONG).show();
            }
            return true;
        }

        return true;
    }

    private static boolean isTrustedWebHost(String host) {
        return host != null
                && (APP_HOST.equalsIgnoreCase(host)
                || "accounts.google.com".equalsIgnoreCase(host)
                || "consent.google.com".equalsIgnoreCase(host)
                || host.toLowerCase().endsWith(".google.com"));
    }

    private boolean isTrustedAppOrigin(Uri origin) {
        return origin != null
                && "https".equalsIgnoreCase(origin.getScheme())
                && APP_HOST.equalsIgnoreCase(origin.getHost());
    }

    private boolean hasCameraPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
                == PackageManager.PERMISSION_GRANTED;
    }

    private static boolean acceptsImage(String[] acceptTypes) {
        if (acceptTypes == null) {
            return false;
        }

        for (String acceptType : acceptTypes) {
            if (acceptType != null && acceptType.toLowerCase().startsWith("image/")) {
                return true;
            }
        }
        return false;
    }

    private void launchFilePicker(WebChromeClient.FileChooserParams params) {
        try {
            Intent intent = params.createIntent();
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            startActivityForResult(intent, FILE_CHOOSER_REQUEST);
        } catch (ActivityNotFoundException exception) {
            finishFileChooser(null);
            Toast.makeText(this, "No document picker is available on this device.", Toast.LENGTH_LONG).show();
        }
    }

    private void launchCameraCapture() {
        File picturesDirectory = new File(getCacheDir(), "camera");
        if (!picturesDirectory.isDirectory() && !picturesDirectory.mkdirs()) {
            finishFileChooser(null);
            Toast.makeText(this, "Camera storage is unavailable on this device.", Toast.LENGTH_LONG).show();
            return;
        }

        try {
            cameraPhotoFile = File.createTempFile("smartapp-camera-", ".jpg", picturesDirectory);
            cameraPhotoUri = FileProvider.getUriForFile(
                    this,
                    getPackageName() + ".fileprovider",
                    cameraPhotoFile
            );

            Intent intent = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
            intent.putExtra(MediaStore.EXTRA_OUTPUT, cameraPhotoUri);
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
            if (intent.resolveActivity(getPackageManager()) == null) {
                discardCameraPhoto();
                finishFileChooser(null);
                Toast.makeText(this, "No camera app is available on this device.", Toast.LENGTH_LONG).show();
                return;
            }

            startActivityForResult(intent, FILE_CHOOSER_REQUEST);
        } catch (IOException | IllegalArgumentException exception) {
            discardCameraPhoto();
            finishFileChooser(null);
            Toast.makeText(this, "The camera could not prepare a photo. Please try again.", Toast.LENGTH_LONG).show();
        } catch (ActivityNotFoundException exception) {
            discardCameraPhoto();
            finishFileChooser(null);
            Toast.makeText(this, "No camera app is available on this device.", Toast.LENGTH_LONG).show();
        }
    }

    private void grantWebCameraPermission() {
        PermissionRequest request = pendingWebPermissionRequest;
        pendingWebPermissionRequest = null;
        if (request != null) {
            request.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
        }
    }

    private DownloadListener createDownloadListener() {
        return (url, userAgent, contentDisposition, mimeType, contentLength) -> {
            Uri downloadUri = Uri.parse(url);
            if (APP_HOST.equalsIgnoreCase(downloadUri.getHost())
                    && downloadUri.getPath() != null
                    && downloadUri.getPath().endsWith("/downloads/smartapp-android.apk")) {
                Toast.makeText(this, "SmartApp is already installed on this device.", Toast.LENGTH_LONG).show();
                return;
            }

            if (!"https".equalsIgnoreCase(downloadUri.getScheme())
                    || !APP_HOST.equalsIgnoreCase(downloadUri.getHost())) {
                Toast.makeText(this, "Only secure SmartApp downloads are supported.", Toast.LENGTH_LONG).show();
                return;
            }

            PendingDownload download = new PendingDownload(url, userAgent, contentDisposition, mimeType);
            if (android.os.Build.VERSION.SDK_INT < android.os.Build.VERSION_CODES.Q
                    && ContextCompat.checkSelfPermission(this, Manifest.permission.WRITE_EXTERNAL_STORAGE)
                    != PackageManager.PERMISSION_GRANTED) {
                pendingDownload = download;
                requestPermissions(
                        new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                        DOWNLOAD_PERMISSION_REQUEST
                );
                return;
            }

            enqueueDownload(download);
        };
    }

    private void enqueueDownload(PendingDownload download) {
        DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (manager == null) {
            Toast.makeText(this, "The system download service is unavailable.", Toast.LENGTH_LONG).show();
            return;
        }

        try {
            Uri uri = Uri.parse(download.url);
            String filename = URLUtil.guessFileName(
                    download.url,
                    download.contentDisposition,
                    download.mimeType
            );
            DownloadManager.Request request = new DownloadManager.Request(uri);
            request.setTitle(filename);
            request.setDescription("Downloading from SmartApp");
            request.setMimeType(download.mimeType);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename);

            String cookies = CookieManager.getInstance().getCookie(download.url);
            if (cookies != null && !cookies.isEmpty()) {
                request.addRequestHeader("Cookie", cookies);
            }
            if (download.userAgent != null && !download.userAgent.isEmpty()) {
                request.addRequestHeader("User-Agent", download.userAgent);
            }

            manager.enqueue(request);
            Toast.makeText(this, "Download started. Check your notifications.", Toast.LENGTH_LONG).show();
        } catch (IllegalArgumentException | SecurityException exception) {
            Toast.makeText(this, "SmartApp could not save this download. Check storage access and try again.", Toast.LENGTH_LONG).show();
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);

        if (requestCode == CAMERA_PERMISSION_REQUEST) {
            boolean granted = grantResults.length > 0
                    && grantResults[0] == PackageManager.PERMISSION_GRANTED;

            if (pendingWebPermissionRequest != null) {
                if (granted) {
                    grantWebCameraPermission();
                } else {
                    pendingWebPermissionRequest.deny();
                    pendingWebPermissionRequest = null;
                }
            }

            if (pendingCameraCapture) {
                pendingCameraCapture = false;
                if (granted) {
                    launchCameraCapture();
                } else {
                    finishFileChooser(null);
                    Toast.makeText(this, "Allow camera access to take an evidence photo.", Toast.LENGTH_LONG).show();
                }
            }
            return;
        }

        if (requestCode == DOWNLOAD_PERMISSION_REQUEST) {
            PendingDownload download = pendingDownload;
            pendingDownload = null;
            if (download == null) {
                return;
            }

            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                enqueueDownload(download);
            } else {
                Toast.makeText(this, "Allow storage access to save downloads on this Android version.", Toast.LENGTH_LONG).show();
            }
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);

        if (requestCode != FILE_CHOOSER_REQUEST || fileChooserCallback == null) {
            return;
        }

        if (cameraPhotoUri != null) {
            Uri capturedPhoto = cameraPhotoUri;
            File capturedFile = cameraPhotoFile;
            boolean photoSaved = resultCode == RESULT_OK
                    && capturedFile != null
                    && capturedFile.isFile()
                    && capturedFile.length() > 0;
            cameraPhotoUri = null;
            cameraPhotoFile = null;
            finishFileChooser(photoSaved ? new Uri[]{capturedPhoto} : null);
            if (!photoSaved) {
                if (capturedFile != null && capturedFile.exists()) {
                    capturedFile.delete();
                }
            }
            return;
        }

        Uri[] results = resultCode == RESULT_OK && data != null
                ? WebChromeClient.FileChooserParams.parseResult(resultCode, data)
                : null;
        finishFileChooser(results);
    }

    private void finishFileChooser(Uri[] results) {
        ValueCallback<Uri[]> callback = fileChooserCallback;
        fileChooserCallback = null;
        if (callback != null) {
            callback.onReceiveValue(results);
        }
    }

    private void discardCameraPhoto() {
        if (cameraPhotoUri != null) {
            deleteCameraPhoto(cameraPhotoUri);
        }
        cameraPhotoUri = null;
        cameraPhotoFile = null;
    }

    private void deleteCameraPhoto(Uri uri) {
        File photoFile = cameraPhotoFile;
        if (photoFile != null && photoFile.exists()) {
            photoFile.delete();
        } else if (uri != null) {
            getContentResolver().delete(uri, null, null);
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        if (webView != null) {
            webView.saveState(outState);
        }
        super.onSaveInstanceState(outState);
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }

    @Override
    protected void onDestroy() {
        if (hideArtworkRunnable != null) {
            mainHandler.removeCallbacks(hideArtworkRunnable);
            hideArtworkRunnable = null;
        }
        if (fileChooserCallback != null) {
            finishFileChooser(null);
        }
        discardCameraPhoto();
        if (pendingWebPermissionRequest != null) {
            pendingWebPermissionRequest.deny();
            pendingWebPermissionRequest = null;
        }
        if (webView != null) {
            webView.stopLoading();
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    private static final class PendingDownload {
        private final String url;
        private final String userAgent;
        private final String contentDisposition;
        private final String mimeType;

        private PendingDownload(
                String url,
                String userAgent,
                String contentDisposition,
                String mimeType
        ) {
            this.url = url;
            this.userAgent = userAgent;
            this.contentDisposition = contentDisposition;
            this.mimeType = mimeType;
        }
    }
}
