/**
 * BCC Trust - Complete Device Fingerprinting
 * @version 1.1.0
 */
(function() {
    'use strict';
    
    class DeviceFingerprinter {
        constructor(options = {}) {
            this.options = Object.assign({
                debug: false,
                endpoint: '/wp-json/bcc-trust/v1/device-fingerprint',
                include: ['screen', 'fonts', 'canvas', 'audio', 'webgl', 'browser', 'hardware', 'behavior']
            }, options);
            
            this.fingerprint = {};
            this.data = {};
            this.ready = false;
            
            if (this.options.debug) console.log('BCC Fingerprinter initialized');
        }
        
        async generate() {
            try {
                // Basic browser info (always included)
                this.collectBrowserInfo();
                
                // Optional components
                if (this.options.include.includes('screen')) await this.collectScreenInfo();
                if (this.options.include.includes('fonts')) await this.collectFonts();
                if (this.options.include.includes('canvas')) await this.collectCanvasFingerprint();
                if (this.options.include.includes('audio')) await this.collectAudioFingerprint();
                if (this.options.include.includes('webgl')) this.collectWebGLInfo();
                if (this.options.include.includes('hardware')) this.collectHardwareInfo();
                if (this.options.include.includes('behavior')) this.collectBehaviorInfo();
                
                // Generate final hash
                this.fingerprint.hash = this.hashData();
                
                // Store in cookies and send to server
                this.storeInCookies();
                await this.sendToServer();
                
                this.ready = true;
                this.triggerEvent('fingerprintReady', this.fingerprint);
                
                return this.fingerprint;
                
            } catch (error) {
                console.error('BCC Fingerprint error:', error);
                this.triggerEvent('fingerprintError', error);
                return null;
            }
        }
        
        collectBrowserInfo() {
            this.data.userAgent = navigator.userAgent;
            this.data.language = navigator.language;
            this.data.languages = navigator.languages ? navigator.languages.join(',') : '';
            this.data.platform = navigator.platform;
            this.data.vendor = navigator.vendor;
            this.data.product = navigator.product;
            this.data.cookiesEnabled = navigator.cookieEnabled;
            this.data.doNotTrack = navigator.doNotTrack;
            this.data.hardwareConcurrency = navigator.hardwareConcurrency || 'unknown';
            this.data.deviceMemory = navigator.deviceMemory || 'unknown';
            this.data.maxTouchPoints = navigator.maxTouchPoints || 0;
            this.data.pdfViewerEnabled = navigator.pdfViewerEnabled || false;
            
            // Check for automation
            this.data.webdriver = navigator.webdriver || false;
            this.data.headless = this.detectHeadless();
            this.data.plugins = this.getPlugins();
        }
        
        detectHeadless() {
            const checks = [];
            
            // Check for headless Chrome specific properties
            if (navigator.webdriver) checks.push('webdriver');
            if (!navigator.plugins.length) checks.push('no_plugins');
            if (navigator.languages.length === 0) checks.push('no_languages');
            
            // Check for PhantomJS
            if (window.callPhantom || window._phantom) checks.push('phantom');
            
            // Check for Puppeteer
            if (navigator.userAgent.includes('HeadlessChrome')) checks.push('headless_chrome');
            
            // Check for Selenium
            if (window.document.documentElement.getAttribute('webdriver')) checks.push('selenium');
            
            // Check for Playwright
            if (window.__playwright) checks.push('playwright');
            
            return checks;
        }
        
        getPlugins() {
            const plugins = [];
            for (let i = 0; i < navigator.plugins.length; i++) {
                plugins.push(navigator.plugins[i].name);
            }
            return plugins.join(',');
        }
        
        async collectScreenInfo() {
            this.data.screenWidth = screen.width;
            this.data.screenHeight = screen.height;
            this.data.screenAvailWidth = screen.availWidth;
            this.data.screenAvailHeight = screen.availHeight;
            this.data.screenColorDepth = screen.colorDepth;
            this.data.screenPixelDepth = screen.pixelDepth;
            this.data.devicePixelRatio = window.devicePixelRatio || 1;
            this.data.innerWidth = window.innerWidth;
            this.data.innerHeight = window.innerHeight;
            this.data.outerWidth = window.outerWidth;
            this.data.outerHeight = window.outerHeight;
        }
        
        async collectFonts() {
            return new Promise((resolve) => {
                try {
                    const fontList = [
                        'Arial', 'Verdana', 'Times New Roman', 'Courier New', 
                        'Helvetica', 'Comic Sans MS', 'Impact', 'Georgia',
                        'Tahoma', 'Trebuchet MS', 'Lucida Console', 'Palatino',
                        'Arial Black', 'Arial Narrow', 'Book Antiqua', 'Century Gothic',
                        'Courier', 'Garamond', 'Geneva', 'Helvetica Neue',
                        'Monaco', 'Optima', 'Symbol', 'Times', 'Zapf Dingbats'
                    ];
                    
                    const available = [];
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Test string
                    const testString = 'mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm';
                    
                    fontList.forEach(font => {
                        ctx.font = '72px ' + font;
                        ctx.fillText(testString, 0, 50);
                        
                        const dataURL = canvas.toDataURL();
                        if (dataURL.length > 1000) { // Rough check if font rendered
                            available.push(font);
                        }
                    });
                    
                    this.data.fonts = available.join(',');
                    resolve();
                } catch (e) {
                    this.data.fonts = 'error';
                    resolve();
                }
            });
        }
        
        async collectCanvasFingerprint() {
            return new Promise((resolve) => {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = 400;
                    canvas.height = 100;
                    const ctx = canvas.getContext('2d');
                    
                    // Draw complex shapes with different styles
                    ctx.textBaseline = 'top';
                    ctx.font = 'bold 24px "Arial", sans-serif';
                    ctx.fillStyle = '#f60';
                    ctx.fillRect(10, 10, 100, 50);
                    
                    // Gradient
                    const gradient = ctx.createLinearGradient(120, 10, 220, 60);
                    gradient.addColorStop(0, '#f00');
                    gradient.addColorStop(0.5, '#0f0');
                    gradient.addColorStop(1, '#00f');
                    ctx.fillStyle = gradient;
                    ctx.fillRect(120, 10, 100, 50);
                    
                    // Text with different styles
                    ctx.fillStyle = '#069';
                    ctx.font = 'italic 20px "Times New Roman", serif';
                    ctx.fillText('Blue Collar Crypto', 10, 70);
                    
                    ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                    ctx.font = 'bold 16px "Courier New", monospace';
                    ctx.fillText('Trust Engine v1', 10, 50);
                    
                    // Circle with pattern
                    ctx.beginPath();
                    ctx.arc(350, 50, 30, 0, Math.PI * 2, true);
                    ctx.fillStyle = '#ff00ff';
                    ctx.shadowColor = '#000';
                    ctx.shadowBlur = 10;
                    ctx.fill();
                    
                    // WebGL check
                    try {
                        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                        if (gl) {
                            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                            if (debugInfo) {
                                ctx.fillStyle = '#000';
                                ctx.font = '10px Arial';
                                ctx.fillText(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL), 10, 90);
                            }
                        }
                    } catch (e) {}
                    
                    // Get data URL
                    const dataURL = canvas.toDataURL();
                    
                    // Create hash
                    this.data.canvas = this.hashString(dataURL);
                    resolve();
                } catch (e) {
                    this.data.canvas = 'error';
                    resolve();
                }
            });
        }
        
        async collectAudioFingerprint() {
            return new Promise((resolve) => {
                try {
                    const AudioContext = window.OfflineAudioContext || window.webkitOfflineAudioContext;
                    if (!AudioContext) {
                        this.data.audio = 'unsupported';
                        return resolve();
                    }
                    
                    const context = new AudioContext(1, 44100, 44100);
                    const oscillator = context.createOscillator();
                    oscillator.type = 'triangle';
                    oscillator.frequency.setValueAtTime(10000, context.currentTime);
                    
                    const compressor = context.createDynamicsCompressor();
                    compressor.threshold.setValueAtTime(-50, context.currentTime);
                    compressor.knee.setValueAtTime(40, context.currentTime);
                    compressor.ratio.setValueAtTime(12, context.currentTime);
                    compressor.attack.setValueAtTime(0, context.currentTime);
                    compressor.release.setValueAtTime(0.25, context.currentTime);
                    
                    oscillator.connect(compressor);
                    compressor.connect(context.destination);
                    
                    oscillator.start(0);
                    
                    context.startRendering().then((renderedBuffer) => {
                        const audioData = renderedBuffer.getChannelData(0);
                        const hash = this.hashAudioData(audioData);
                        this.data.audio = hash;
                        resolve();
                    }).catch(() => {
                        this.data.audio = 'error';
                        resolve();
                    });
                } catch (e) {
                    this.data.audio = 'error';
                    resolve();
                }
            });
        }
        
        collectWebGLInfo() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                
                if (gl) {
                    const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                    if (debugInfo) {
                        this.data.webglVendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL);
                        this.data.webglRenderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
                    }
                    
                    // Get WebGL parameters
                    this.data.webglVersion = gl.getParameter(gl.VERSION);
                    this.data.webglShadingLanguage = gl.getParameter(gl.SHADING_LANGUAGE_VERSION);
                    this.data.webglVendor = gl.getParameter(gl.VENDOR);
                    this.data.webglRenderer = gl.getParameter(gl.RENDERER);
                    this.data.webglMaxTextureSize = gl.getParameter(gl.MAX_TEXTURE_SIZE);
                    this.data.webglMaxViewportDims = gl.getParameter(gl.MAX_VIEWPORT_DIMS);
                    
                    // Check for headless WebGL
                    if (this.data.webglRenderer.includes('SwiftShader') || 
                        this.data.webglRenderer.includes('llvmpipe') ||
                        this.data.webglRenderer.includes('Mesa')) {
                        this.data.webglHeadless = true;
                    }
                }
            } catch (e) {
                this.data.webgl = 'error';
            }
        }
        
        collectHardwareInfo() {
            this.data.cpuCores = navigator.hardwareConcurrency || 'unknown';
            this.data.deviceMemory = navigator.deviceMemory || 'unknown';
            this.data.maxTouchPoints = navigator.maxTouchPoints || 0;
            this.data.touchSupport = 'ontouchstart' in window;
            this.data.pixelRatio = window.devicePixelRatio || 1;
            this.data.colorDepth = screen.colorDepth || 24;
            this.data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            this.data.timezoneOffset = new Date().getTimezoneOffset();
        }
        
        collectBehaviorInfo() {
            // Track mouse movement
            let mouseMoved = false;
            let mousePositions = [];
            
            document.addEventListener('mousemove', (e) => {
                mouseMoved = true;
                mousePositions.push({ x: e.clientX, y: e.clientY, time: Date.now() });
                // Keep only last 10 positions
                if (mousePositions.length > 10) mousePositions.shift();
            });
            
            // Track scrolling
            let scrolled = false;
            document.addEventListener('scroll', () => {
                scrolled = true;
            });
            
            // Check after 3 seconds
            setTimeout(() => {
                this.data.mouseMoved = mouseMoved;
                this.data.scrolled = scrolled;
                
                // Calculate mouse movement pattern
                if (mousePositions.length > 5) {
                    const distances = [];
                    for (let i = 1; i < mousePositions.length; i++) {
                        const dx = mousePositions[i].x - mousePositions[i-1].x;
                        const dy = mousePositions[i].y - mousePositions[i-1].y;
                        distances.push(Math.sqrt(dx*dx + dy*dy));
                    }
                    const avgDistance = distances.reduce((a,b) => a+b, 0) / distances.length;
                    this.data.avgMouseDistance = avgDistance;
                }
                
                this.storeInCookies();
            }, 3000);
            
            // Track visibility
            let visible = true;
            document.addEventListener('visibilitychange', () => {
                visible = !document.hidden;
            });
            this.data.windowVisible = visible;
            
            // Track time on page
            this.data.timeOnPage = 0;
            setInterval(() => {
                this.data.timeOnPage += 1;
            }, 1000);
        }
        
        hashString(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(36);
        }
        
        hashAudioData(data) {
            let hash = 0;
            for (let i = 0; i < Math.min(data.length, 1000); i++) {
                const val = Math.floor(data[i] * 10000);
                hash = ((hash << 5) - hash) + val;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(36);
        }
        
        hashData() {
            const dataStr = JSON.stringify(this.data);
            return this.hashString(dataStr);
        }
        
        storeInCookies() {
            const expiry = new Date();
            expiry.setTime(expiry.getTime() + 30 * 24 * 60 * 60 * 1000);
            
            // Store fingerprint hash
            document.cookie = `bcc_fingerprint=${this.fingerprint.hash}; expires=${expiry.toUTCString()}; path=/; SameSite=Lax`;
            
            // Store individual components
            for (const [key, value] of Object.entries(this.data)) {
                if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
                    document.cookie = `bcc_${key}=${encodeURIComponent(String(value))}; expires=${expiry.toUTCString()}; path=/; SameSite=Lax`;
                }
            }

            // Store combined screen resolution (PHP expects bcc_screen as "WxH")
            if (this.data.screenWidth && this.data.screenHeight) {
                document.cookie = `bcc_screen=${this.data.screenWidth}x${this.data.screenHeight}; expires=${expiry.toUTCString()}; path=/; SameSite=Lax`;
            }
        }
        
        async sendToServer() {
            if (!window.bccTrust || !window.bccTrust.rest_url) {
                console.warn('BCC Trust: REST URL not available');
                return;
            }
            
            try {
                const response = await fetch(window.bccTrust.rest_url + 'device-fingerprint', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.bccTrust.nonce
                    },
                    body: JSON.stringify({
                        fingerprint: this.fingerprint,
                        data: this.data,
                        timestamp: Date.now(),
                        url: window.location.href,
                        referrer: document.referrer
                    })
                });
                
                if (response.ok && this.options.debug) {
                    console.log('BCC Trust: Fingerprint sent to server');
                }
            } catch (e) {
                if (this.options.debug) {
                    console.log('BCC Trust: Failed to send fingerprint', e);
                }
            }
        }
        
        triggerEvent(name, detail) {
            const event = new CustomEvent(name, { detail });
            document.dispatchEvent(event);
        }
    }
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFingerprinter);
    } else {
        initFingerprinter();
    }
    
    /**
     * Check if the user has granted fingerprinting consent.
     * Consent can be set by:
     *   - A cookie `bcc_fp_consent=1` (set by your cookie-consent banner)
     *   - The server-side flag `window.bccTrust.fingerprint_consent`
     */
    function hasConsent() {
        // Server-side opt-in (e.g., admin setting, logged-in user who accepted ToS)
        if (window.bccTrust && window.bccTrust.fingerprint_consent) {
            return true;
        }
        // Client-side cookie set by a consent banner
        return document.cookie.split(';').some(function(c) {
            return c.trim().startsWith('bcc_fp_consent=1');
        });
    }

    function initFingerprinter() {
        // Only initialize if trust widgets exist
        if (!document.querySelector('.bcc-trust-wrapper') && !document.querySelector('[data-bcc-fingerprint]')) {
            return;
        }

        // Wait for bccTrust global to be available
        if (typeof window.bccTrust === 'undefined') {
            // Try again in 500ms
            setTimeout(initFingerprinter, 500);
            return;
        }

        // GDPR/CCPA: Do NOT collect fingerprints until consent is granted.
        if (!hasConsent()) {
            // Expose a stub so the rest of the app can check readiness.
            window.bccFingerprinter = { ready: false, fingerprint: {} };
            // Listen for deferred consent (e.g., user clicks "Accept" later).
            document.addEventListener('bccConsentGranted', function onConsent() {
                document.removeEventListener('bccConsentGranted', onConsent);
                startFingerprinting();
            });
            return;
        }

        startFingerprinting();
    }

    function startFingerprinting() {
        var fingerprinter = new DeviceFingerprinter({
            debug: window.bccTrust.debug || false
        });

        // Store globally
        window.bccFingerprinter = fingerprinter;

        // Generate fingerprint
        fingerprinter.generate();

        // Add to existing widgets
        document.querySelectorAll('.bcc-trust-wrapper').forEach(function(wrapper) {
            wrapper.setAttribute('data-fingerprint-ready', 'false');
        });

        document.addEventListener('fingerprintReady', function() {
            document.querySelectorAll('.bcc-trust-wrapper').forEach(function(wrapper) {
                wrapper.setAttribute('data-fingerprint-ready', 'true');
                wrapper.setAttribute('data-fingerprint', fingerprinter.fingerprint.hash);
            });
        });
    }
})();