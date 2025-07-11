@inject('captcha', 'App\Services\CaptchaService')

<div class="newsletter-signup-container">
    @if(!$subscribed)
        <div class="bg-gradient-to-r from-gray-50 to-slate-50 border border-gray-300 rounded-lg p-6 shadow-sm" style="border-color: #19939f;">
            <div class="flex items-center mb-4">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #19939f;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                <h3 class="text-lg font-semibold text-gray-800">Stay Updated</h3>
            </div>
            
            <p class="text-gray-600 mb-4 text-sm">
                Get notified when we add new features or discover interesting insights about online reviews.
            </p>

            <form wire:submit.prevent="subscribe" class="space-y-4" onsubmit="handleFormSubmit(event)">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <input 
                            type="email" 
                            wire:model="email" 
                            placeholder="Enter your email address"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:border-transparent transition-colors text-sm"
                            style="--tw-ring-color: #19939f;"
                            :disabled="loading"
                            onfocus="if(typeof executeRecaptcha === 'function') executeRecaptcha();"
                        />
                        @error('email')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <button 
                        type="submit" 
                        class="px-6 py-3 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors font-medium text-sm whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center"
                        style="background-color: #19939f; --tw-ring-color: #19939f;"
                        onmouseover="this.style.backgroundColor='#167a84'"
                        onmouseout="this.style.backgroundColor='#19939f'"
                        :disabled="loading"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove>Subscribe</span>
                        <span wire:loading class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Subscribing...
                        </span>
                    </button>
                </div>

                @if(!app()->environment(['local', 'testing']))
                    <!-- reCAPTCHA v3 (invisible) -->
                    <input type="hidden" wire:model="g_recaptcha_response">
                @endif
            </form>
        </div>
    @endif

    <!-- Success/Error Messages -->
    @if($message)
        <div class="mt-4 p-4 rounded-lg {{ $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800' }}">
            <div class="flex items-center">
                @if($messageType === 'success')
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                @else
                    <svg class="w-5 h-5 mr-2 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                @endif
                <span class="text-sm font-medium">{{ $message }}</span>
            </div>
            
            @if($subscribed)
                <button 
                    wire:click="resetForm" 
                    class="mt-3 text-sm underline hover:no-underline"
                    style="color: #19939f;"
                    onmouseover="this.style.color='#167a84'"
                    onmouseout="this.style.color='#19939f'"
                >
                    Subscribe another email
                </button>
            @endif
        </div>
    @endif
</div>

@if(!app()->environment(['local', 'testing']))
    @push('scripts')
        <!-- reCAPTCHA v3 Script -->
        <script src="https://www.google.com/recaptcha/api.js?render={{ $captcha->getSiteKey() }}" defer></script>
        <script>
            let recaptchaLoaded = false;
            let recaptchaInterval;
            let newsletterComponent = null;

            // Initialize when everything is ready
            function initNewsletter() {
                if (window.Livewire && typeof grecaptcha !== 'undefined') {
                    try {
                        newsletterComponent = @this;
                        initializeRecaptcha();
                    } catch(e) {
                        console.log('Waiting for component...', e.message);
                        setTimeout(initNewsletter, 1000);
                    }
                } else {
                    setTimeout(initNewsletter, 1000);
                }
            }

            function initializeRecaptcha() {
                if (typeof grecaptcha !== 'undefined' && !recaptchaLoaded) {
                    grecaptcha.ready(function() {
                        recaptchaLoaded = true;
                        console.log('reCAPTCHA v3 loaded successfully');
                        executeRecaptcha();
                        
                        // Set up automatic token refresh every 100 seconds
                        if (recaptchaInterval) clearInterval(recaptchaInterval);
                        recaptchaInterval = setInterval(executeRecaptcha, 100000);
                    });
                } else if (typeof grecaptcha === 'undefined') {
                    console.log('reCAPTCHA not loaded yet, retrying...');
                    setTimeout(initializeRecaptcha, 2000);
                }
            }

            function executeRecaptcha() {
                if (typeof grecaptcha !== 'undefined' && recaptchaLoaded && newsletterComponent) {
                    console.log('Executing reCAPTCHA...');
                    grecaptcha.execute('{{ $captcha->getSiteKey() }}', {action: 'newsletter_signup'})
                        .then(function(token) {
                            console.log('reCAPTCHA token generated:', token.substring(0, 20) + '...');
                            try {
                                newsletterComponent.set('g_recaptcha_response', token);
                                console.log('Token sent to component');
                            } catch(e) {
                                console.error('Failed to set token:', e);
                            }
                        })
                        .catch(function(error) {
                            console.error('reCAPTCHA execution error:', error);
                        });
                } else {
                    console.log('reCAPTCHA not ready:', {
                        grecaptcha: typeof grecaptcha !== 'undefined',
                        loaded: recaptchaLoaded,
                        component: !!newsletterComponent
                    });
                }
            }

            // Start initialization when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initNewsletter);
            } else {
                initNewsletter();
            }

            // Handle Livewire events
            document.addEventListener('livewire:init', function() {
                console.log('Livewire initialized');
                
                Livewire.on('resetRecaptcha', function() {
                    console.log('Reset reCAPTCHA event received');
                    executeRecaptcha();
                });
                
                Livewire.on('generateRecaptcha', function() {
                    console.log('Generate reCAPTCHA event received');
                    executeRecaptcha();
                });
            });

            // Handle form submission - ensure fresh token
            function handleFormSubmit(event) {
                console.log('Form submit intercepted');
                if (recaptchaLoaded) {
                    executeRecaptcha();
                    // Small delay to ensure token is set
                    setTimeout(function() {
                        console.log('Proceeding with form submission');
                    }, 100);
                }
            }
        </script>
    @endpush
@endif 