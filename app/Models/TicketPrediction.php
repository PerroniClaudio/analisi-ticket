<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class TicketPrediction extends Model {
    protected $fillable = [
        'ticket_id',
        'company_name',
        'subject',
        'description',
        'ticket_type',
        'channel',
        'ticket_data',
        'predicted_minutes',
        'confidence_score',
        'model_version',
        'model_response',
        'status',
        'error_message',
        'predicted_at'
    ];

    protected $casts = [
        'ticket_data' => 'array',
        'model_response' => 'array',
        'predicted_at' => 'datetime',
        'predicted_minutes' => 'integer',
        'confidence_score' => 'float'
    ];

    public function predictedMinutesFormatted(): Attribute {
        return Attribute::make(
            get: function () {
                if (!$this->predicted_minutes) {
                    return null;
                }

                $hours = intdiv($this->predicted_minutes, 60);
                $minutes = $this->predicted_minutes % 60;

                if ($hours > 0) {
                    return "{$hours}h {$minutes}m";
                }

                return "{$minutes}m";
            }
        );
    }

    public function scopeSuccessful($query) {
        return $query->where('status', 'processed')
            ->whereNotNull('predicted_minutes');
    }

    public function scopeByCompany($query, $company) {
        return $query->where('company_name', $company);
    }
}
