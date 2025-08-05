<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnalysisSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'user_session',
        'asin',
        'product_url',
        'status',
        'current_step',
        'progress_percentage',
        'current_message',
        'total_steps',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'result' => 'array',
        'progress_percentage' => 'float',
        'current_step' => 'integer',
        'total_steps' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function updateProgress(int $step, float $percentage, string $message): void
    {
        // For long-running jobs, use a fresh instance to avoid staleness issues
        $fresh = static::find($this->id);
        if ($fresh) {
            $fresh->current_step = $step;
            $fresh->progress_percentage = $percentage;
            $fresh->current_message = $message;
            $fresh->save();
            
            // Update current instance to stay in sync
            $this->current_step = $step;
            $this->progress_percentage = $percentage;
            $this->current_message = $message;
        } else {
            // Fallback if fresh instance not found
            $this->current_step = $step;
            $this->progress_percentage = $percentage;
            $this->current_message = $message;
            $this->save();
        }
        
        // Log progress update for debugging
        \Illuminate\Support\Facades\Log::info("Progress update committed to database", [
            'session_id' => $this->id,
            'step' => $step,
            'percentage' => $percentage,
            'message' => $message
        ]);
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(array $result): void
    {
        $this->update([
            'status' => 'completed',
            'result' => $result,
            'progress_percentage' => 100.0,
            'current_message' => 'Analysis complete!',
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }
} 