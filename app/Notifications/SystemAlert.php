<?php

namespace App\Notifications;

use App\Enums\AlertType;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Messages\PushoverMessage;
use Illuminate\Notifications\Notification;

class SystemAlert extends Notification
{
    public function __construct(
        private AlertType $type,
        private string $message,
        private array $context = [],
        private ?int $priority = null,
        private ?string $url = null,
        private ?string $urlTitle = null
    ) {
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return [PushoverChannel::class];
    }

    /**
     * Get the Pushover representation of the notification.
     */
    public function toPushover($notifiable): array
    {
        $message = new PushoverMessage($this->message);

        $message->title($this->type->getDisplayName());

        // Set priority
        $priority = $this->priority ?? $this->type->getDefaultPriority();
        $message->priority($priority);

        // Set sound
        if ($sound = $this->type->getDefaultSound()) {
            $message->sound($sound);
        }

        // Set URL if provided
        if ($this->url) {
            $message->url($this->url, $this->urlTitle);
        }

        // Add context information to message if present
        if (!empty($this->context)) {
            $contextStr = $this->formatContext($this->context);
            if ($contextStr) {
                $fullMessage = $this->message."\n\n".$contextStr;
                $message = new PushoverMessage($fullMessage);
                $message->title($this->type->getDisplayName());
                $message->priority($priority);
                if ($sound = $this->type->getDefaultSound()) {
                    $message->sound($sound);
                }
                if ($this->url) {
                    $message->url($this->url, $this->urlTitle);
                }
            }
        }

        return $message->toArray();
    }

    /**
     * Format context information for display.
     */
    private function formatContext(array $context): string
    {
        $formatted = [];

        foreach ($context as $key => $value) {
            // Skip sensitive or overly verbose data
            if (in_array($key, ['trace', 'password', 'token', 'secret'])) {
                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $formatted[] = ucfirst(str_replace('_', ' ', $key)).': '.$value;
            } elseif (is_bool($value)) {
                $formatted[] = ucfirst(str_replace('_', ' ', $key)).': '.($value ? 'Yes' : 'No');
            } elseif (is_array($value) && count($value) <= 3) {
                $formatted[] = ucfirst(str_replace('_', ' ', $key)).': '.implode(', ', $value);
            }
        }

        return implode("\n", array_slice($formatted, 0, 5)); // Limit to 5 context items
    }

    /**
     * Get the alert type.
     */
    public function getAlertType(): AlertType
    {
        return $this->type;
    }

    /**
     * Get the alert context.
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
