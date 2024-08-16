<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ApiLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'ip',
        'script_id',
        'provider',
        'model',
        'request_type',
        'request',
        'response',
        'success',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'response_time'
    ];


    protected $appends = ['api_call_cost'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Uncomment if using Laravel Jetstream with Teams
    // public function team()
    // {
    //     return $this->belongsTo(Team::class, 'team_id');
    // }

    public function getApiCallCostAttribute()
    {
        $promptCostPerToken = 0;
        $completionCostPerToken = 0;

        switch ($this->provider) {
            case 'OpenAI':
                switch ($this->model) {
                    case 'gpt-3.5-turbo':
                        $promptCostPerToken = 0.001 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.002 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-3.5-turbo-0125':
                        $promptCostPerToken = 0.0005 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.0015 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-3.5-turbo-16k':
                        $promptCostPerToken = 0.003 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.004 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-4-turbo-preview':
                        $promptCostPerToken = 0.01 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.03 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-4-turbo':
                        $promptCostPerToken = 0.01 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.03 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-4o':
                        $promptCostPerToken = 0.005 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.015 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-4o-2024-08-06':
                        $promptCostPerToken = 0.0025 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.010 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'gpt-4o-mini':
                        $promptCostPerToken = 0.150 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 0.600 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                }
                break;
            case 'Anthropic':
                switch ($this->model) {
                    case 'claude-3-opus-20240229':
                        $promptCostPerToken = 15 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 75 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                    case 'claude-3-sonnet-20240229':
                        $promptCostPerToken = 3 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 15 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                    case 'claude-3-5-sonnet-20240620':
                        $promptCostPerToken = 3 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 15 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                    case 'claude-3-haiku-20240307':
                        $promptCostPerToken = 0.25 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 1.25 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                }
                break;
            case 'OpenRouter':
                switch ($this->model) {
                    case 'openai/gpt-3.5-turbo':
                        $promptCostPerToken = 0.001 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.002 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'openai/gpt-3.5-turbo-16k':
                        $promptCostPerToken = 0.003 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.004 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'openai/gpt-4-turbo-preview':
                        $promptCostPerToken = 0.01 / 1000; // Prompt cost per 1,000 tokens
                        $completionCostPerToken = 0.03 / 1000; // Completion cost per 1,000 tokens
                        break;
                    case 'anthropic/claude-3-opus':
                        $promptCostPerToken = 15 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 75 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                    case 'anthropic/claude-3-sonnet':
                        $promptCostPerToken = 3 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 15 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                    case 'anthropic/claude-3-5-sonnet-20240620':
                        $promptCostPerToken = 3 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 15 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                    case 'anthropic/claude-3-haiku':
                        $promptCostPerToken = 0.25 / 1000000; // Prompt cost per 1,000,000 tokens
                        $completionCostPerToken = 1.25 / 1000000; // Completion cost per 1,000,000 tokens
                        break;
                }
                break;
        }

        // Assuming separate counts for prompt and completion tokens.
        $totalPromptCost = $this->prompt_tokens * $promptCostPerToken;
        $totalCompletionCost = $this->completion_tokens * $completionCostPerToken;

        return $totalPromptCost + $totalCompletionCost;
    }

}
