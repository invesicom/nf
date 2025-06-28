<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushoverChannel
{
    /**
     * Send the given notification.
     */
    public function send($notifiable, Notification $notification)
    {
        $message = $notification->toPushover($notifiable);

        if (!$message) {
            return;
        }

        $response = Http::timeout(30)
            ->retry(3, 5000) // Retry 3 times with 5 second delay
            ->post('https://api.pushover.net/1/messages.json', $message);

        // Log the response for debugging
        if ($response->successful()) {
            $responseData = $response->json();
            Log::info('Pushover notification sent successfully', [
                'request_id' => $responseData['request'] ?? null,
                'status' => $responseData['status'] ?? null,
            ]);
        } else {
            $statusCode = $response->status();
            $responseBody = $response->body();
            
            Log::error('Pushover notification failed', [
                'status_code' => $statusCode,
                'response' => $responseBody,
                'message_data' => $message,
            ]);

            // Handle specific error cases
            if ($statusCode >= 400 && $statusCode < 500) {
                // Client error - don't retry
                Log::warning('Pushover client error - check configuration', [
                    'status' => $statusCode,
                    'response' => $responseBody,
                ]);
            } elseif ($statusCode >= 500) {
                // Server error - already retried via Http::retry()
                Log::error('Pushover server error after retries', [
                    'status' => $statusCode,
                    'response' => $responseBody,
                ]);
            }
        }

        return $response;
    }
} 