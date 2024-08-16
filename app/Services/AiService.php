<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use App\Models\ApiLog;
use App\Exceptions\AiException;
use WpAi\Anthropic\Facades\Anthropic;
use Yethee\Tiktoken\EncoderProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected $userId;
    protected $teamId;
    public $provider;
    protected $model;
    private $responseTime;

    public function __construct(
        int $userId = null,
        int $teamId = null,
        string $provider = 'OpenAI',
        string $model = 'gpt-4o-2024-08-06'
    ) {
        $this->userId = $userId;
        $this->teamId = $teamId;
        $this->provider = $provider;
        $this->model = $model;
    }

    private function setOpenAiKeys() {
        Config::set('openai.api_key', env('OPENAI_API_KEY'));
        Config::set('openai.base_uri', env('OPENAI_BASE_URI', 'api.openai.com/v1'));
    }

    private function setOpenRouterKeys() {
        Config::set('openai.api_key', env('OPENROUTER_API_KEY'));
        Config::set('openai.base_uri', env('OPENROUTER_BASE_URI'));
    }

    public function useGPT4()
    {
        $this->provider = 'OpenAI';
        $this->model = 'gpt-4o-2024-08-06';
        $this->setOpenAiKeys();
    }

    public function useGPT4Mini()
    {
        $this->provider = 'OpenAI';
        $this->model = 'gpt-4o-mini';
        $this->setOpenAiKeys();
    }

    public function useClaudeHaiku() {
        $this->provider = 'Anthropic';
        $this->model = 'claude-3-haiku-20240307';

        // $this->provider = 'OpenRouter';
        // $this->model = 'anthropic/claude-3-haiku';
        // $this->setOpenRouterKeys();
    }

    public function useClaudeSonnet() {
        $this->provider = 'Anthropic';
        $this->model = 'claude-3-5-sonnet-20240620';
        // $this->model = 'claude-3-sonnet-20240229';

        // $this->provider = 'OpenRouter';
        // $this->model = 'anthropic/claude-3-sonnet';
        // $this->setOpenRouterKeys();
    }

    public function useClaudeOpus() {
        $this->provider = 'Anthropic';
        $this->model = 'claude-3-5-sonnet-20240620';
        // $this->model = 'claude-3-opus-20240229';

        // $this->provider = 'OpenRouter';
        // $this->model = 'anthropic/claude-3-opus';
        // $this->setOpenRouterKeys();
    }

    /**
     * Sends a chat request to the AI provider and handles the response.
     *
     * @param string $requestType The type of request being made (e.g. 'research_summary')
     * @param array $messages An array of message objects to send to the AI
     * @param bool $returnJson Whether to request a JSON response from the AI
     * @param float $temperature The temperature setting for the AI's response (0-1)
     * @param callable|false $streamedCallback A callback function to handle streamed responses, or false for non-streamed
     * @param int $maxTokens The maximum number of tokens to generate in the response
     * @return mixed The AI's response
     * @throws AiException If there's an error in the AI request or response
     */
    public function chat($requestType, $messages, $returnJson = false, $temperature = 0, callable|false $streamedCallback = false, $maxTokens = 4096)
    {
        try {
            // Build the query based on the provider and request parameters
            $query = $this->buildQuery($messages, $returnJson, $temperature, $maxTokens);

            // Send the request to the AI provider and get the response
            $response = $this->createChatRequest($query, $streamedCallback);

            // Check if the AI request finished successfully
            $finish_reason = $this->getFinishReason($response);
            if (!in_array($finish_reason, ['stop', 'end_turn', 'stop_sequence'])) {
                // If the finish reason is unexpected, throw an exception
                $errorMessage = is_array($response) && isset($response['error']) ? $response['error'] : 'AI API error: ' . $finish_reason;
                $this->logApiRequest($messages, $response, $requestType, false);
                throw new AiException($errorMessage, $finish_reason);
            }

            // Log the successful API request
            $this->logApiRequest($messages, $response, $requestType, true);

            return $response;

        } catch (\OpenAI\Exceptions\ErrorException $e) {
            // Handle OpenAI specific exceptions
            $this->logApiRequest($messages, $e->getCode() . ': '  . $e->getMessage(), $requestType, false);
            throw new AiException($e->getMessage(), $e->getCode());

        } catch (\Exception $e) {
            // Handle any other unexpected exceptions
            $this->logApiRequest($messages, $e->getMessage(), $requestType, false);
            throw $e;
        }
    }

    /**
     * Builds the query array for the AI request based on the provider.
     *
     * @param array $messages The messages to send to the AI
     * @param bool $returnJson Whether to request a JSON response
     * @param float $temperature The temperature setting for the AI
     * @param int $maxTokens The maximum number of tokens to generate
     * @return array The constructed query
     */
    private function buildQuery($messages, $returnJson, $temperature, $maxTokens)
    {
        $query = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        if ($this->provider === 'OpenAI' || $this->provider === 'OpenRouter') {
            // Add OpenAI/OpenRouter specific parameters
            $query['user'] = (string) $this->userId;
            $query['max_tokens'] = $maxTokens;
            if ($returnJson) {
                $query['response_format'] = ['type' => 'json_object'];
            }
        } elseif ($this->provider === 'Anthropic') {
            // Add Anthropic specific parameters
            $query['metadata']['user_id'] = (string) $this->userId;
            $query['max_tokens'] = $maxTokens;
            $query['stop_sequences'] = ['<stop>', '<wrapup>'];
            $query = $this->processAnthropicSystemMessages($query);
            if ($returnJson) {
                $query['messages'][] = [ 'role' => 'assistant', 'content' => '{' ];
            }
        }

        return $query;
    }

    /**
     * Processes system messages for Anthropic queries.
     *
     * @param array $query The initial query array
     * @return array The processed query with system messages handled
     */
    private function processAnthropicSystemMessages($query)
    {
        $system_msg = [];
        foreach ($query['messages'] as $key => $message) {
            if ($message['role'] === 'system') {
                $system_msg[] = $message['content'];
                unset($query['messages'][$key]);
            }
        }
        if ($system_msg) {
            $query['system'] = implode("\n", $system_msg);
            $query['messages'] = array_values($query['messages']);
        }
        return $query;
    }

    /**
     * Creates a chat request to the AI provider, handling both streamed and non-streamed responses.
     *
     * @param array $query The query parameters for the AI request
     * @param false|callable $streamedCallback Callback function for handling streamed responses, or false for non-streamed
     * @return mixed The response from the AI provider
     */
    private function createChatRequest($query, false|callable $streamedCallback)
    {
        $this->responseTime = 0;
        $startTime = microtime(true);

        // Handle streamed response if requested and supported
        if ($streamedCallback !== false && is_callable($streamedCallback)) {
            $streamedData = [];

            if ($this->provider === 'OpenAI' || $this->provider === 'OpenRouter') {
                $streamedResponse = OpenAI::chat()->createStreamed($query);

                foreach ($streamedResponse as $chunk) {
                    $streamedData[] = $chunk;
                    $streamedCallback($chunk); // Broadcast each chunk
                }
                $response = $this->processOpenAIStreamedData($streamedData, $query);

            } elseif ($this->provider === 'Anthropic') {
                $query['stream'] = true;
                $query['model'] = $this->model;
                $streamedResponse = Anthropic::messages()->stream($query, ['anthropic-beta' => 'max-tokens-3-5-sonnet-2024-07-15']);

                foreach ($streamedResponse as $chunk) {
                    $streamedData[] = $chunk;
                    $streamedCallback($chunk); // Broadcast each chunk
                }
                $response = $this->processAnthropicStreamedData($streamedData);
            }

        // Handle non-streamed request
        } else {
            if ($this->provider === 'OpenAI' || $this->provider === 'OpenRouter') {
                $response = OpenAI::chat()->create($query);
            } elseif ($this->provider === 'Anthropic') {
                $query['model'] = $this->model;
                $response = Anthropic::messages()->create($query, ['anthropic-beta' => 'max-tokens-3-5-sonnet-2024-07-15']);
            }
        }

        $endTime = microtime(true);
        $this->responseTime = ($endTime - $startTime) * 1000; // Calculate response time in milliseconds

        return $response;
    }

    /**
     * Process streamed data from OpenAI or OpenRouter
     *
     * @param array $streamedData The streamed response data
     * @param array $query The original query sent to the API
     * @return array Processed response in a standardized format
     */
    private function processOpenAIStreamedData($streamedData, array $query)
    {
        if (is_object($streamedData)) {
            $streamedData = $streamedData->toArray();
        }

        $responseText = '';

        // Concatenate the content from each chunk
        foreach ($streamedData as $chunk) {
            if (
                isset($chunk['choices']) &&
                (!isset($chunk['choices'][0]['delta']['role']) || $chunk['choices'][0]['delta']['role'] === 'assistant') &&
                isset($chunk['choices'][0]['delta']['content'])
            ) {
                $responseText .= $chunk['choices'][0]['delta']['content'];
            }
        }

        // Calculate token usage
        // Note: This is an estimation and may not be accurate for all models, especially through OpenRouter
        $provider = new EncoderProvider();
        $model = $this->provider === 'OpenRouter' ? 'gpt-4-turbo-preview' : $this->model;
        $encoder = $provider->getForModel($model);
        $queryString = implode("\n", array_map(function($message) { return $message['content']; }, $query['messages']));
        $tokens = $encoder->encode($queryString);

        $promptTokens = count($tokens);
        $completionTokens = count($streamedData);
        $totalTokens = $promptTokens + $completionTokens;

        // Construct the response in a standardized format
        $response = [
            'choices' => [
                [
                    'streamed' => true,
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => $responseText
                    ]
                ]
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ]
        ];

        return $response;
    }

    /**
     * Process streamed data from Anthropic
     *
     * @param array $streamedData The streamed response data
     * @return object Processed response in a standardized format
     */
    private function processAnthropicStreamedData($streamedData)
    {
        if (is_object($streamedData)) {
            $streamedData = $streamedData->toArray();
        }

        $responseText = '';
        $input_tokens = 0;
        $output_tokens = 0;

        // Process each chunk of the streamed data
        foreach ($streamedData as $chunk) {
            if (
                isset($chunk['type']) &&
                $chunk['type'] === 'content_block_delta' &&
                isset($chunk['delta'])
            ) {
                $responseText .= $chunk['delta']['text'];
            } else if (
                isset($chunk['type']) &&
                $chunk['type'] === 'message_start' &&
                isset($chunk['message'])
            ) {
                $input_tokens = $chunk['message']['usage']['input_tokens'];
            } else if (
                isset($chunk['type']) &&
                $chunk['type'] === 'message_delta' &&
                isset($chunk['delta']) &&
                $chunk['delta']['stop_reason'] === 'end_turn'
            ) {
                $output_tokens = $chunk['usage']['output_tokens'];
            }
        }

        // Construct the response in a standardized format
        $response = new \stdClass();
        $response->usage = new \stdClass();
        $response->usage->inputTokens = $input_tokens;
        $response->usage->outputTokens = $output_tokens;
        $response->content = [ ['text' => $responseText] ];
        $response->streamed = true;
        $response->stopReason = 'end_turn';

        return $response;
    }

    /**
     * Used in stream callbacks to retrieve the contents depending on the model
     * @param array|object $chunk The chunk of data from the stream
     * @return string|null The content of the chunk, or null if not found
     */
    public static function getStreamContent($chunk) {
        // Convert object to array if necessary
        if (is_object($chunk)) {
            $chunk = $chunk->toArray();
        }

        // Handle OpenAI format
        if (
            isset($chunk['choices']) &&
            (!isset($chunk['choices'][0]['delta']['role']) || $chunk['choices'][0]['delta']['role'] === 'assistant')
             &&
            isset($chunk['choices'][0]['delta']['content'])
        ) {
            return $chunk['choices'][0]['delta']['content'];
        }

        // Handle Anthropic format
        if (
            isset($chunk['type']) &&
            $chunk['type'] === 'content_block_delta' &&
            isset($chunk['delta'])
        ) {
            return $chunk['delta']['text'];
        }

        // Return null if content not found
        return null;
    }

    /**
     * Get the finish reason from the AI response
     * @param array|object $response The AI response
     * @return string The finish reason
     */
    public function getFinishReason($response) {
        if ($this->provider === 'OpenAI' || $this->provider === 'OpenRouter') {
            return $response && isset($response['choices']) && $response['choices'][0]
                ? $response['choices'][0]['finish_reason']
                : 'Undefined finish reason';
        }

        if ($this->provider === 'Anthropic') {
            return $response && isset($response->stopReason)
                ? $response->stopReason
                : 'Undefined finish reason';
        }

        return 'Unknown provider';
    }

    /**
     * Extract the content from the AI response
     * @param array|object $response The AI response
     * @return string|null The extracted content
     */
    public function getContent($response) {
        if ($this->provider === 'OpenAI' || $this->provider === 'OpenRouter') {
            return $response && isset($response['choices']) && isset($response['choices'][0])
                ? $response['choices'][0]['message']['content']
                : null;
        }

        if ($this->provider === 'Anthropic') {
            // Handle string responses (usually errors)
            if (is_string($response)) {
                return $response;
            }

            if ($response && isset($response->content) && isset($response->content[0])) {
                $text = trim($response->content[0]['text']);

                // Add opening bracket if the response looks like JSON
                if (strpos($text, '}') > 0 && strpos($text, ':') !== false) {
                    $text = '{' . $text;
                }
                return $text;
            }
        }

        return null;
    }

    /**
     * Handle timeout errors by retrying the request
     * NOTE: This is not used in the current implementation, but leaving in case it's helpful
     * @param array $query The query to send to the AI
     * @param array $messages The messages to send to the AI
     * @param string $requestType The type of request
     * @param int $maxRetries Maximum number of retries
     * @return array|null The AI response, or null if all retries fail
     * @throws AiException If max retries are exceeded or an error occurs
     */
    private function handleTimeout($query, $messages, $requestType, $maxRetries = 3)
    {
        $retryCount = 0;
        $waitTime = 1; // Initial wait time in seconds

        while ($retryCount < $maxRetries) {
            try {
                sleep($waitTime);
                $response = $this->createChatRequest($query, false);

                $finish_reason = $this->getFinishReason($response);

                if ($finish_reason === 'stop') {
                    return $response;
                } elseif ($finish_reason !== 'timeout') {
                    $errorMessage = $response['error'] ?? 'Unknown error';
                    $this->logApiRequest($messages, $response, $requestType, false);
                    throw new AiException($errorMessage, $response['status'] ?? 'Unknown status');
                }
            } catch (\Exception $e) {
                $this->logApiRequest($messages, $e->getMessage(), $requestType, false);
            }

            $retryCount++;
            $waitTime *= 2; // Exponential backoff
        }

        throw new AiException('Maximum retries exceeded for timeout', 'timeout');
    }
    /**
     * Generate an image based on a given prompt
     *
     * @param string $prompt The description of the image to generate
     * @param string $size The size of the image (default: '1024x1024')
     * @return string The URL of the generated image
     * @throws AiException If there's an error in image generation
     */
    public function image($prompt, $size = '1024x1024')
    {
        $this->responseTime = 0;
        $startTime = microtime(true);
        $this->model = 'dall-e-3';
        $this->setOpenAiKeys();

        try {
            // Make the API call to generate the image
            $response = OpenAI::images()->create([
                'prompt' => $prompt,
                'model' => $this->model,
                'n' => 1,
                'size' => $size
            ]);

            $endTime = microtime(true);
            $this->responseTime = ($endTime - $startTime) * 1000; // Calculate the response time in milliseconds

            // Check if the response is valid
            if (!$response || !$response['data'] || count($response['data']) === 0) {
                $errorMessage = $response['error'] ?? 'Unknown error';
                $this->logApiRequest($prompt, $response, 'AiImage', false);
                throw new AiException($errorMessage, 'Unknown');
            }

            $this->logApiRequest($prompt, $response, 'AiImage', true);

            // Return the URL of the generated image
            return $response['data'][0]['url'];

        } catch (\OpenAI\Exceptions\ErrorException $e) {
            // Handle exceptions thrown by the OpenAI library
            $this->logApiRequest($prompt, $e->getCode() . ': '  . $e->getMessage(), 'AiImage', false);
            throw new AiException($e->getMessage(), $e->getCode());

        } catch (\Exception $e) {
            // Handle any other exceptions
            $this->logApiRequest($prompt, $e->getMessage(), 'AiImage', false);
            throw $e;
        }
    }

    /**
     * Log the API request and response
     *
     * @param mixed $request The request data
     * @param mixed $response The response data
     * @param string $requestType The type of request (e.g., 'AiImage')
     * @param bool $success Whether the request was successful
     */
    private function logApiRequest($request, $response, $requestType, $success)
    {
        // Determine usage based on the provider
        if ($this->provider === 'OpenAI' || $this->provider === 'OpenRouter') {
            $usage = $response['usage'] ?? null;
        } else if ($this->provider === 'Anthropic') {
            if (is_object($response)) {
                $usage = [
                    'prompt_tokens' => $response->usage->inputTokens,
                    'completion_tokens' => $response->usage->outputTokens,
                    'total_tokens' => $response->usage->inputTokens + $response->usage->outputTokens
                ];
            } else {
                // For errors, set usage to zero
                $usage = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0
                ];
            }
        }

        // Prepare content for logging
        $content = $success ? $this->getContent($response) : $response;

        // Create and save the API log
        $apiLog = new ApiLog;
        $apiLog->user_id = $this->userId;
        $apiLog->team_id = $this->teamId;
        $apiLog->ip = request()->ip();
        $apiLog->provider = $this->provider;
        $apiLog->model = $this->model;
        $apiLog->request_type = $requestType;
        $apiLog->request = is_array($request) || is_object($request) ? json_encode($request) : $request;
        $apiLog->response = is_array($content) || is_object($content) ? json_encode($content) : ( $content ?? 'n/a' );
        $apiLog->success = $success;
        $apiLog->prompt_tokens = $usage ? $usage['prompt_tokens'] : null;
        $apiLog->completion_tokens = $usage ? $usage['completion_tokens'] : null;
        $apiLog->total_tokens = $usage ? $usage['total_tokens'] : null;
        $apiLog->response_time = $this->responseTime;
        $apiLog->save();
    }
}
