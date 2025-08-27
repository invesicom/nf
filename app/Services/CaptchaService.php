<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

/**
 * Captcha Service for handling multiple CAPTCHA providers.
 *
 * This service provides a unified interface for both reCAPTCHA and hCaptcha
 * verification systems, automatically using the configured provider.
 */
class CaptchaService
{
    /**
     * Get the site key for the configured captcha provider.
     *
     * @return string The public site key
     */
    public function getSiteKey(): string
    {
        $provider = config('captcha.provider');

        return config("captcha.{$provider}.site_key");
    }

    /**
     * Get the currently configured captcha provider.
     *
     * @return string The provider name ('recaptcha' or 'hcaptcha')
     */
    public function getProvider(): string
    {
        return config('captcha.provider');
    }

    /**
     * Verify a captcha response token with the configured provider.
     *
     * @param string      $token The captcha response token from the client
     * @param string|null $ip    Optional client IP address for verification
     *
     * @return bool True if verification successful, false otherwise
     */
    public function verify(string $token, ?string $ip = null): bool
    {
        $provider = config('captcha.provider');
        $secret = config("captcha.{$provider}.secret_key");
        $url = config("captcha.{$provider}.verify_url");

        $response = Http::asForm()->post($url, array_filter([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]));

        $data = $response->json();

        // Both APIs return 'success' boolean
        return $data['success'] ?? false;
    }

    /**
     * Check if captcha re-validation is required based on session submission count.
     *
     * @return bool True if captcha is required, false otherwise
     */
    public function isRevalidationRequired(): bool
    {
        if (!config('captcha.revalidation.enabled', true)) {
            return false;
        }

        if (app()->environment(['local', 'testing'])) {
            return false;
        }

        $sessionKey = config('captcha.revalidation.session_key', 'captcha_submissions');
        $threshold = config('captcha.revalidation.submission_threshold', 4);
        
        $submissionCount = Session::get($sessionKey, 0);
        
        return $submissionCount >= $threshold;
    }

    /**
     * Record a successful product submission in the session.
     */
    public function recordSuccessfulSubmission(): void
    {
        if (!config('captcha.revalidation.enabled', true)) {
            return;
        }

        $sessionKey = config('captcha.revalidation.session_key', 'captcha_submissions');
        $currentCount = Session::get($sessionKey, 0);
        
        Session::put($sessionKey, $currentCount + 1);
    }

    /**
     * Reset the submission counter (called after successful captcha verification).
     */
    public function resetSubmissionCounter(): void
    {
        $sessionKey = config('captcha.revalidation.session_key', 'captcha_submissions');
        Session::forget($sessionKey);
    }

    /**
     * Get the current submission count for debugging/display purposes.
     *
     * @return int Current submission count
     */
    public function getSubmissionCount(): int
    {
        $sessionKey = config('captcha.revalidation.session_key', 'captcha_submissions');
        return Session::get($sessionKey, 0);
    }

    /**
     * Check if captcha validation should be enforced (not in local/testing environment).
     *
     * @return bool True if captcha should be enforced
     */
    public function shouldEnforceCaptcha(): bool
    {
        return !app()->environment(['local', 'testing']);
    }
}
