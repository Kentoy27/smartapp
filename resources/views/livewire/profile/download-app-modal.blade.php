<div class="profile-modal-host">
    @if ($showModal)
        <div
            class="modal-backdrop is-open"
            wire:click.self="closeModal"
            x-on:keydown.escape.window="$wire.closeModal()"
            role="presentation"
        >
            <section
                class="modal download-app-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="downloadAppTitle"
                x-data="smartAppInstallModal()"
                x-init="init()"
            >
                <div class="modal-head">
                    <div>
                        <span class="download-app-modal__eyebrow">SMARTAPP FOR YOUR DEVICES</span>
                        <h2 id="downloadAppTitle">Install SmartApp</h2>
                    </div>
                    <button
                        type="button"
                        class="modal-close"
                        wire:click="closeModal"
                        aria-label="Close install app"
                        title="Close"
                    >×</button>
                </div>

                <div class="download-app-modal__body">
                    <div class="download-app-modal__brand" aria-hidden="true">
                        <img src="{{ public_asset('smartapp-logo.png') }}?v=3" alt="">
                        <div>
                            <strong>SmartApp</strong>
                            <span>School management, ready when you are.</span>
                        </div>
                    </div>

                    <p class="download-app-modal__description">
                        Install SmartApp on this device for an app-style experience. Your account works on the web and in the installed app.
                    </p>

                    <div class="download-app-modal__unlimited">
                        <x-icon name="check-circle" :size="17" />
                        <span>Install on as many supported devices as you need. There is no device limit.</span>
                    </div>

                    <section class="download-app-modal__android" aria-labelledby="downloadAppAndroidTitle">
                        <div>
                            <span class="download-app-modal__android-label">ANDROID APP</span>
                            <h3 id="downloadAppAndroidTitle">Install the SmartApp Android app</h3>
                            <p>Download the Android installer (APK), then open it on your phone to install SmartApp.</p>
                        </div>
                        @if (is_file(public_path('downloads/smartapp-android.apk')))
                            <a class="btn-primary download-app-modal__apk" href="{{ route('app.android.download', ['v' => 4]) }}" x-show="!nativeApp" x-cloak>
                                <x-icon name="download" :size="17" />
                                <span>Download Android APK (v1.0.3)</span>
                            </a>
                            <p class="download-app-modal__apk-note" x-show="!nativeApp" x-cloak>Android may ask you to allow installs from this browser. Only install APKs you downloaded from SmartApp.</p>
                            <p class="download-app-modal__apk-note" x-show="nativeApp" x-cloak role="status">SmartApp is already installed on this device. You do not need to install another copy.</p>
                        @else
                            <p class="download-app-modal__apk-note" role="status">The Android installer is being prepared. Browser installation is still available below.</p>
                        @endif
                    </section>

                    <section class="download-app-modal__ios" aria-labelledby="downloadAppIosTitle">
                        <div>
                            <span class="download-app-modal__ios-label">IPHONE &amp; IPAD</span>
                            <h3 id="downloadAppIosTitle">Install SmartApp on iPhone or iPad</h3>
                            <p>iOS cannot install Android APK files. Add the SmartApp web app to your Home Screen from Safari instead.</p>
                        </div>
                        <ol>
                            <li>Open SmartApp in Safari.</li>
                            <li>Tap <strong>Share</strong>, then choose <strong>Add to Home Screen</strong>.</li>
                            <li>Tap <strong>Add</strong>. SmartApp will appear on your Home Screen.</li>
                        </ol>
                    </section>

                    <div class="download-app-modal__status" x-show="installed" x-cloak role="status">
                        <x-icon name="check-circle" :size="17" />
                        <span>SmartApp is already installed on this device.</span>
                    </div>

                    <button
                        type="button"
                        class="btn-primary download-app-modal__install"
                        x-show="canInstall && !installed"
                        x-cloak
                        x-on:click="install()"
                    >
                        <x-icon name="download" :size="17" />
                        <span>Install SmartApp</span>
                    </button>

                    <p class="download-app-modal__result" x-show="message" x-cloak x-text="message" role="status"></p>

                    <div class="download-app-modal__instructions" x-show="!canInstall && !installed" x-cloak>
                        <h3>Install from your browser</h3>
                        <ul>
                            <li><strong>Android:</strong> open the browser menu and choose <em>Install app</em> or <em>Add to Home screen</em>.</li>
                            <li><strong>Computer:</strong> use the install icon in the address bar, or choose <em>Install SmartApp</em> from the browser menu.</li>
                        </ul>
                        <p>Install options depend on your browser. Open SmartApp in a supported, up-to-date browser over a secure connection.</p>
                    </div>
                </div>
            </section>
        </div>
    @endif
</div>
