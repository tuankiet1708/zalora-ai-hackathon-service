<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiApiController extends BaseController
{
    const MESSAGE_TO_ASK_FILTER_SUGGESTION_TEMPLATE = 'Extract the category, color, brand, store and price from the content "%s" and return as json format';

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        return response()->json([
            'message' => "I'm " . $request->input('name', 'OpenAI API')
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function filter(Request $request)
    {
        $originalFilterFromLotus = (array) $request->input('filter', []);
        $customerContent = $request->input('content', '');

        // Make request to Open AI
        $result = $this->callOpenAi(
            $this->buildMessageToAskFilterSuggestionFromOpenAi($customerContent)
        );

        // Extract answer from Open AI
        $message = $this->extractOpenAiMessage($result);

        $suggestedFilterFromOpenAi = @json_decode($message, true);

        // Rebuild the filter with suggestion from Open AI
        $filter = $this->rebuildFilter($originalFilterFromLotus, (array) $suggestedFilterFromOpenAi);

        return response()->json([
            'data' => $filter
        ]);
    }

    /**
     * @param string $content
     * @return string
     */
    protected function buildMessageToAskFilterSuggestionFromOpenAi(string $content): string
    {
        return sprintf(
            self::MESSAGE_TO_ASK_FILTER_SUGGESTION_TEMPLATE,
            $content
        );
    }

    /**
     * @param array $originalFilterFromLotus
     * @param array $suggestedFilterFromOpenAi
     * @return array
     */
    protected function rebuildFilter(array $originalFilterFromLotus, array $suggestedFilterFromOpenAi): array
    {
        // TODO: This is just an example, need to implement here.
        return array_merge_recursive($originalFilterFromLotus, $suggestedFilterFromOpenAi);
    }

    ###### FUNCTIONS TO WORK WITH OPEN AI ######

    /**
     * @param string $message
     * @return array
     */
    protected function callOpenAi(string $message): array
    {
        $result = [];

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->acceptJson()
                ->post(env('OPENAI_URL'), [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $message
                        ]
                    ],
                    'temperature' => 0.7
                ]);

            $result = $response->json();
        } catch (Throwable $ex) {
            //
        }

        return $result;
    }

    /**
     * @param array $result
     * @return string
     */
    protected function extractOpenAiMessage(array $result): string
    {
        return $result['choices'][0]['message']['content'] ?? '';
    }
}
