/**
 * AIMF Secure Contact Form — Public JavaScript
 * Vanilla JS (no jQuery dependency) form submission handler.
 *
 * Security features:
 * - Lazy-fetched JS token (not in page HTML — fetched via AJAX)
 * - Interaction gate (token only fetched after real user interaction)
 * - Headless browser detection (navigator.webdriver, HeadlessChrome UA)
 * - Single-use tokens with anti-replay (each token works once)
 * - Server-signed form render timestamp (HMAC, can't be forged)
 * - Cloudflare Turnstile / Google reCAPTCHA v3 / hCaptcha support
 */
(function () {
    'use strict';

    var form = document.getElementById('ascf-contact-form');
    if (!form) {
        return;
    }

    var submitBtn = document.getElementById('ascf-submit-btn');
    var submitText = submitBtn ? submitBtn.querySelector('.ascf-submit-text') : null;
    var spinner = submitBtn ? submitBtn.querySelector('.ascf-submit-spinner') : null;
    var responseDiv = document.getElementById('ascf-response');
    var tokenInput = document.getElementById('ascf-captcha-token');
    var jsTokenInput = document.getElementById('ascf-js-token');
    var turnstileContainer = document.getElementById('ascf-turnstile-container');

    var config = window.ascf_config || {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';
    var formId = config.formId || 1;
    var hasRecaptcha = config.hasRecaptcha || false;
    var hasHcaptcha = config.hasHcaptcha || false;
    var hasTurnstile = config.hasTurnstile || false;
    var recaptchaSiteKey = config.recaptchaSiteKey || '';
    var turnstileSiteKey = config.turnstileSiteKey || '';
    var turnstileAction = config.turnstileAction || 'aimf_secure_form';

    var originalBtnText = submitText ? submitText.textContent : 'Send Message';
    var turnstileWidgetId = null;

    // --- Interaction-gated token fetch state ---
    var interactionDetected = false;
    var jsTokenReady = false;
    var tokenFetchInFlight = false;

    /**
     * Detect headless/automated browsers.
     *
     * Checks navigator.webdriver (spec-mandated automation flag) and the
     * HeadlessChrome UA token. These catch lazy automation that hasn't
     * applied stealth patches. Determined attackers can bypass these, but
     * they still have to pass CAPTCHA, anti-replay, and server-side checks.
     *
     * @returns {boolean} True if headless indicators are detected.
     */
    function isHeadlessBrowser() {
        // navigator.webdriver is true under Selenium/Puppeteer/Playwright.
        if (navigator.webdriver === true) {
            return true;
        }
        // Default headless Chrome UA contains "HeadlessChrome".
        if (/HeadlessChrome/i.test(navigator.userAgent)) {
            return true;
        }
        return false;
    }

    /**
     * Fetch a unique JS token from the server via AJAX.
     *
     * The token is NOT in the page HTML. It's generated server-side with a
     * random ID + HMAC signature, making each token unique and single-use.
     * This endpoint is only called after a real user interaction is detected.
     */
    function fetchToken() {
        if (tokenFetchInFlight || jsTokenReady) {
            return;
        }
        tokenFetchInFlight = true;

        var fd = new FormData();
        fd.set('action', 'ascf_get_token');
        fd.set('_wpnonce', nonce);

        fetch(ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                tokenFetchInFlight = false;
                if (data.success && data.data && data.data.token) {
                    jsTokenReady = true;
                    if (jsTokenInput) {
                        jsTokenInput.value = data.data.token;
                    }
                }
            })
            .catch(function () {
                tokenFetchInFlight = false;
            });
    }

    /**
     * Handle the first real user interaction.
     *
     * Called once on the first mousemove, keydown, touchstart, scroll, or
     * click event. If the browser passes headless checks, fetches the JS
     * token from the server. This ensures:
     * 1. HTML scrapers never see the token (it's not in the page)
     * 2. Headless bots without stealth patches don't get a token
     * 3. The token fetch proves a real browser environment is running
     */
    function onFirstInteraction() {
        if (interactionDetected) {
            return;
        }
        interactionDetected = true;
        removeInteractionListeners();

        // Silently refuse to fetch token for headless browsers.
        // The form will look normal but submission will fail server-side.
        if (isHeadlessBrowser()) {
            return;
        }

        fetchToken();
    }

    /**
     * Set up interaction event listeners on the document.
     * Any of these events proves a real user is present.
     */
    var interactionEvents = ['mousemove', 'keydown', 'touchstart', 'scroll', 'click'];

    function addInteractionListeners() {
        for (var i = 0; i < interactionEvents.length; i++) {
            document.addEventListener(interactionEvents[i], onFirstInteraction, { passive: true });
        }
    }

    function removeInteractionListeners() {
        for (var i = 0; i < interactionEvents.length; i++) {
            document.removeEventListener(interactionEvents[i], onFirstInteraction);
        }
    }

    /**
     * Render the Cloudflare Turnstile widget if configured.
     */
    function renderTurnstile() {
        if (!hasTurnstile || !turnstileContainer) {
            return;
        }
        if (typeof turnstile === 'undefined') {
            return;
        }
        turnstileWidgetId = turnstile.render(turnstileContainer, {
            sitekey: turnstileSiteKey,
            theme: 'dark',
            size: 'normal',
            action: turnstileAction,
            cData: 'form_' + String(formId),
            callback: function (token) {
                if (tokenInput) {
                    tokenInput.value = token;
                }
            },
            'expired-callback': function () {
                if (tokenInput) {
                    tokenInput.value = '';
                }
            },
            'error-callback': function () {
                if (tokenInput) {
                    tokenInput.value = '';
                }
            }
        });
    }

    /**
     * Get the Turnstile token from the rendered widget.
     *
     * @returns {string} The Turnstile token, or empty if not available.
     */
    function getTurnstileToken() {
        if (!hasTurnstile || typeof turnstile === 'undefined' || turnstileWidgetId === null) {
            return '';
        }
        return turnstile.getResponse(turnstileWidgetId) || '';
    }

    /**
     * Display an inline message (success or error).
     *
     * @param {string} text     The message text.
     * @param {string} type     'success' or 'error'.
     */
    function showMessage(text, type) {
        if (!responseDiv) {
            return;
        }
        responseDiv.textContent = text;
        responseDiv.className = 'ascf-response ascf-' + type;

        if (type === 'success') {
            form.reset();
            // Token was consumed (anti-replay). Clear and fetch a fresh one.
            if (jsTokenInput) {
                jsTokenInput.value = '';
            }
            jsTokenReady = false;
            fetchToken();
            // Reset Turnstile widget if present.
            if (hasTurnstile && typeof turnstile !== 'undefined' && turnstileWidgetId !== null) {
                turnstile.reset(turnstileWidgetId);
            }
        }

        // Scroll the response into view.
        responseDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /**
     * Set the loading state on the submit button.
     *
     * @param {boolean} loading Whether loading.
     */
    function setLoading(loading) {
        if (!submitBtn) {
            return;
        }
        submitBtn.disabled = loading;
        if (spinner) {
            spinner.style.display = loading ? 'inline-block' : 'none';
        }
        if (submitText) {
            submitText.textContent = loading ? 'Sending...' : originalBtnText;
        }
    }

    /**
     * Get a reCAPTCHA v3 token via grecaptcha.execute.
     *
     * @returns {Promise<string>}
     */
    function getRecaptchaToken() {
        return new Promise(function (resolve, reject) {
            if (typeof grecaptcha === 'undefined' || !grecaptcha.execute) {
                reject(new Error('reCAPTCHA not loaded'));
                return;
            }
            grecaptcha.ready(function () {
                grecaptcha.execute(recaptchaSiteKey, { action: 'submit' })
                    .then(function (token) {
                        resolve(token);
                    })
                    .catch(function (err) {
                        reject(err);
                    });
            });
        });
    }

    /**
     * Get an hCaptcha token from the hCaptcha widget if present.
     *
     * @returns {Promise<string>}
     */
    function getHcaptchaToken() {
        return new Promise(function (resolve, reject) {
            if (typeof hcaptcha === 'undefined' || !hcaptcha.execute) {
                reject(new Error('hCaptcha not loaded'));
                return;
            }
            try {
                var token = hcaptcha.execute();
                if (token && typeof token.then === 'function') {
                    token.then(resolve).catch(reject);
                } else {
                    resolve(token);
                }
            } catch (err) {
                reject(err);
            }
        });
    }

    /**
     * Send the AJAX submission.
     *
     * @param {string} captchaToken The CAPTCHA token (empty if no CAPTCHA configured).
     */
    function sendSubmission(captchaToken) {
        var formData = new FormData(form);

        if (tokenInput) {
            tokenInput.value = captchaToken || '';
        }

        // Ensure action, nonce, and CAPTCHA token are set.
        // JS token is already in the hidden field (lazy-fetched).
        // ascf_form_loaded is already in the HTML (server-signed timestamp).
        formData.set('action', 'ascf_submit_form');
        formData.set('_wpnonce', nonce);
        formData.set('ascf_captcha_token', captchaToken || '');
        formData.set('ascf_js_token', jsTokenInput ? jsTokenInput.value : '');

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                setLoading(false);
                if (data.success) {
                    var msg = (data.data && data.data.message) ? data.data.message : 'Thank you for your message.';
                    showMessage(msg, 'success');
                } else {
                    var errMsg = (data.data && data.data.message) ? data.data.message : 'An error occurred. Please try again.';
                    showMessage(errMsg, 'error');
                }
            })
            .catch(function () {
                setLoading(false);
                showMessage('A network error occurred. Please try again.', 'error');
            });
    }

    /**
     * Handle the form submit event.
     *
     * @param {Event} e The submit event.
     */
    function handleSubmit(e) {
        e.preventDefault();

        setLoading(true);

        // Clear any previous response.
        if (responseDiv) {
            responseDiv.textContent = '';
            responseDiv.className = 'ascf-response';
        }

        // Check that the JS token is ready (lazy-fetched after interaction).
        // If the user's first interaction is clicking submit, the token won't
        // be ready yet. Show a helpful message and trigger the fetch.
        if (!jsTokenInput || !jsTokenInput.value) {
            setLoading(false);
            // Trigger interaction detection if not already done (the click
            // itself will have fired onFirstInteraction, starting the fetch).
            if (!interactionDetected) {
                onFirstInteraction();
            }
            showMessage('Verifying your browser. Please try again in a moment.', 'error');
            return;
        }

        if (hasTurnstile) {
            var turnstileToken = getTurnstileToken();
            if (!turnstileToken) {
                setLoading(false);
                showMessage('Please complete the security check.', 'error');
                return;
            }
            sendSubmission(turnstileToken);
        } else if (hasRecaptcha) {
            getRecaptchaToken()
                .then(function (token) {
                    sendSubmission(token);
                })
                .catch(function () {
                    setLoading(false);
                    showMessage('CAPTCHA verification failed. Please refresh the page and try again.', 'error');
                });
        } else if (hasHcaptcha) {
            getHcaptchaToken()
                .then(function (token) {
                    sendSubmission(token);
                })
                .catch(function () {
                    setLoading(false);
                    showMessage('CAPTCHA verification failed. Please refresh the page and try again.', 'error');
                });
        } else {
            sendSubmission('');
        }
    }

    // --- Initialization ---

    // Start listening for user interaction (token is fetched on first event).
    addInteractionListeners();

    // Render Turnstile widget after the Turnstile script loads.
    if (hasTurnstile) {
        if (typeof turnstile !== 'undefined') {
            renderTurnstile();
        } else {
            // Wait for the Turnstile script to load.
            var turnstileCheckInterval = setInterval(function () {
                if (typeof turnstile !== 'undefined') {
                    renderTurnstile();
                    clearInterval(turnstileCheckInterval);
                }
            }, 200);
            // Stop checking after 10 seconds.
            setTimeout(function () {
                clearInterval(turnstileCheckInterval);
            }, 10000);
        }
    }

    form.addEventListener('submit', handleSubmit);
})();
