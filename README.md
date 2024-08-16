# Laravel AI Boilerplate

This is a starting point for building a Laravel application with AI capabilities.

Features:
- Laravel 11
- OpenAI and Anthropic text completion support
- OpenAI DALL-E support
- Streaming responses
- Logging of requests and responses

# Basic Chat Usage

```
<?php
use App\Services\AiService;

class ExampleController extends Controller
{
    public function generateContent(Request $request)
    {
        $user_id = $request->user()->id;
        $team_id = $request->user()->currentTeam->id ?? null; // if using Laravel Jetstream with Teams

        $aiService = new AiService($user_id, $team_id);
        $aiService->useGPT4(); // or useClaudeSonnet(), useClaudeHaiku(), etc.

        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Generate a short story about a robot.']
        ];

        try {
            $response = $aiService->chat('content_generation', $messages, false, 0.7);
            $content = $aiService->getContent($response);
            return response()->json(['content' => $content]);
        
        } catch (\App\Exceptions\AiException $e) {
            $errorMessage = '';
            // Handle AiException errors
            switch ($e->getStatus()) {
                case 'length':
                    $errorMessage = 'This data is too long for the AI service. Please shorten it and try again.';
                    break;
                case 'rate':
                case 'resources':
                case 'content_filter':
                    $errorMessage = 'The AI service is currently unavailable. Please try again later.';
                    break;
                case 'timeout':
                    // Handle 'timeout' status
                    // The request timed out
                    // You should retry the request
                    $errorMessage = 'The AI service took too long to respond. Please try again.';
                    break;
                default:
                    // Handle unknown status
                    $errorMessage = 'An unknown error occurred. Please try again later.';
                    break;
            }
        }
    }

    public function generateImage(Request $request)
    {
        $aiService = new AiService($request->user()->id, $request->user()->currentTeam->id);
        
        try {
            $imageUrl = $aiService->image('A futuristic city with flying cars', '1024x1024');
            return response()->json(['image_url' => $imageUrl]);
        } catch (AiException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}?>
```

# Streaming Responses

## View
```
<div wire:stream="previewText">{{ $previewText }}</div>
```

## Livewire Component
```
<?php
public function generate() 
{
    $this->stream(to: "previewText", content: 'Generating preview...', replace: true);

    $firstChunk = true;
    $generate = new PreviewJob($this->previewId, function($chunk) use (&$firstChunk) {
        $content = AiService::getStreamContent($chunk);

        if ($content) {
            // Replace the loading text when receiving the first chunk
            if ($firstChunk) {
                $this->stream(to: "previewText", content: $content, replace: true);
                $firstChunk = false;
            } else {
                $this->stream(to: "previewText", content: $content);
            }
        }
    }, auth()->user()->id, auth()->user()->currentTeam->id);
    $this->previewText = $generate->stream();
}
```
## Job Code
```
<?php
class PreviewJob
{
    protected $previewId;
    protected $userId;
    protected $teamId;
    protected $callbackFunc;

    public function __construct(int $previewId, callable|false $callbackFunc = false, int|false $userId = false, int|false $teamId = false)
    {
        $this->previewId = $previewId;
        $this->userId = $userId ?? auth()->user()->id;
        $this->teamId = $teamId ?? auth()->user()->currentTeam->id;
        $this->callbackFunc = $callbackFunc;
    }

    public function stream()
    {
        $messages = $this->buildPrompts();

        $errorMessage = null;

        try 
        {
            $aiService = new AiService($this->userId, $this->userId);
            $aiService->useClaudeOpus();

            $query = $aiService->chat('generatePreview', $messages, false, 1, $this->callbackFunc);

        } catch (\App\Exceptions\AiException $e) {
            // Handle AiException errors
            switch ($e->getStatus()) {
                case 'length':
                    $errorMessage = 'Your script is too long for the AI service. Please shorten it and try again.';
                    break;
                case 'rate':
                case 'resources':
                case 'content_filter':
                    $errorMessage = 'The AI service is currently unavailable. Please try again later.';
                    break;
                case 'timeout':
                    // Handle 'timeout' status
                    // The request timed out
                    // You should retry the request
                    $errorMessage = 'The AI service took too long to respond. Please try again.';
                    break;
                default:
                    // Handle unknown status
                    $errorMessage = 'An unknown error occurred. Please try again later.';
                    break;
            }
        }

        // if streaming, let's go ahead and notify the frontend
        // this will not execute if we are generating an auto draft
        if (!is_null($this->callbackFunc)) {
            if ($errorMessage) {
                Log::error("Error generating AI streaming response", [
                    'error' => $errorMessage,
                ]);
                return false;
            }
        }

        $result = $aiService->getContent($query);

        return $result;
    }

    private function buildPrompts() 
    {
        // Build your $messages array
    }
}
?>```
