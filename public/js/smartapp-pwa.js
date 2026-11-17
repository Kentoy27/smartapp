(function () {
    var installPrompt = null;

    function isInstalled() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
    }

    function notifyState() {
        window.dispatchEvent(new CustomEvent('smartapp-pwa-state', {
            detail: {
                canInstall: installPrompt !== null,
                installed: isInstalled()
            }
        }));
    }

    window.smartAppInstallModal = function () {
        return {
            canInstall: installPrompt !== null,
            installed: isInstalled(),
            message: '',
            init: function () {
                var self = this;
                window.addEventListener('smartapp-pwa-state', function (event) {
                    self.canInstall = Boolean(event.detail && event.detail.canInstall);
                    self.installed = Boolean(event.detail && event.detail.installed);
                });
            },
            install: async function () {
                if (!installPrompt) {
                    this.canInstall = false;
                    return;
                }

                var prompt = installPrompt;
                installPrompt = null;

                try {
                    await prompt.prompt();
                    var choice = await prompt.userChoice;
                    this.message = choice.outcome === 'accepted'
                        ? 'SmartApp is being installed on this device.'
                        : 'Installation was cancelled. You can try again from your browser menu.';
                } catch (error) {
                    console.error('SmartApp installation prompt failed.', error);
                    this.message = 'The installation prompt could not be opened. Please use your browser menu to install SmartApp.';
                } finally {
                    notifyState();
                }
            }
        };
    };

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
        notifyState();
    });

    window.addEventListener('appinstalled', function () {
        installPrompt = null;
        notifyState();
    });

    if ('serviceWorker' in navigator) {
        var config = document.getElementById('smartAppPwaConfig');

        if (config) {
            navigator.serviceWorker.register(config.dataset.serviceWorker, {
                scope: config.dataset.scope
            }).catch(function (error) {
                console.error('SmartApp service worker registration failed.', error);
            });
        }
    }

    if (window.matchMedia) {
        var displayMode = window.matchMedia('(display-mode: standalone)');

        if (displayMode.addEventListener) {
            displayMode.addEventListener('change', notifyState);
        } else if (displayMode.addListener) {
            displayMode.addListener(notifyState);
        }
    }
})();
