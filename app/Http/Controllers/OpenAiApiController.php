<?php

namespace App\Http\Controllers;

use App\Models\GeneralFilter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiApiController extends BaseController
{
    const MESSAGE_TO_ASK_FILTER_SUGGESTION_TEMPLATE = 'Extract the content "%s" and only return as following json format {category: string, color: array, brand: array, rating: string, occasion: string, discount: int, material: string, pattern: string, range: string, condition: string, price_min_value: integer, price_max_value: integer}';
    const MESSAGE_TO_ASK_FILTER_PRICE_SUGGESTION_TEMPLATE = 'Extract the content "%s" and only return as following json format {price_min_value: integer, price_max_value: integer}' ;
    const MESSAGE_TO_ASK_SIMPLE_KEYWORD_SUGGESTION_TEMPLATE = 'Extract main product name from content "%s" and only return as following json {main_product_name: string}' ;

    const LABEL = 'Label';
    const WIDGET = 'Widget';
    const ID = 'Id';
    const OPTIONS = 'Options';
    const SELECTED = 'Selected';
    const VALUE = 'Value';
    const RESULT_COUNT= 'ResultCount';
    const DEFAULT = 'default';
    const WEIGHT_LABEL = [
        'color' => 1,
        'default' => 1
    ];

    const FILTER_IDS_LOTUS_OPENAI_MAP = [
        'brandIds[]' => 'brand',  //
        'categoryId' => 'category', //
        // 'price' => 'price',
        'rating' => 'rating', //
        // 'promotions' => 'promotion',
        'colors[]' => 'color', //
        'occasion' => 'occasion', //
        'discount' => 'discount',  //
        'material_composition' => 'material', //
        'condition' => 'condition',
        'pattern' => 'pattern', //
        'range' => 'range' //
    ];

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
        $originalFilterFromLotus = GeneralFilter::generateGeneralFilter();
        $customerContent = $request->input('content', '');

        // Make request to Open AI with customerContent
        $result = $this->concurrentRequestsToOpenAi($customerContent);

        // Extract answers from Open AI
        $suggestedFilterFromOpenAi = (array) @json_decode($result[0], true);
        $suggestedSimpleKeywordFromOpenAi = (array) @json_decode($result[1], true);

        Log::info("OPEN_AI_RESULT", [
            $suggestedFilterFromOpenAi,
            $suggestedSimpleKeywordFromOpenAi
        ]);

        // Rebuild the filter with suggestion from Open AI
        $filter = $this->rebuildFilter($originalFilterFromLotus, $suggestedFilterFromOpenAi);
        $simpleKeyword = $this->extractSimpleKeyword($suggestedSimpleKeywordFromOpenAi);

        return response()->json([
            'data' => compact('simpleKeyword', 'filter')
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
     * @param string $content
     * @return string
     */
    protected function buildMessageToAskFilterPriceSuggestionFromOpenAi(string $content): string
    {
        return sprintf(
            self::MESSAGE_TO_ASK_FILTER_PRICE_SUGGESTION_TEMPLATE,
            $content
        );
    }

    /**
     * @param string $content
     * @return string
     */
    protected function buildMessageToAskSimpleKeywordSuggestionFromOpenAi(string $content): string
    {
        return sprintf(
            self::MESSAGE_TO_ASK_SIMPLE_KEYWORD_SUGGESTION_TEMPLATE,
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
        $modifiedFilter = [];

        foreach ($originalFilterFromLotus as $filterByIndex) {
            $modifiedFilterByIndex = $filterByIndex;

            // particular process for price filter
            if ($filterByIndex[self::ID] === 'price') {
                list($minValue, $maxValue) = $this->extractMinMaxPrice($suggestedFilterFromOpenAi);

                if (empty($minValue) && empty($maxValue)) {
                    continue;
                }

                $modifiedFilterByIndex[self::OPTIONS][] = [
                    'Value' => $this->buildValueOptionForPrice($minValue, $maxValue),
                    'Label' => $this->buildValueOptionForPrice($minValue, $maxValue),
                    'Selected' => true,
                    'SubOptions' => [],
                    'ResultCount' => 0
                ];
                $modifiedFilter[] = $modifiedFilterByIndex;

                continue;
            }

            if (!isset(self::FILTER_IDS_LOTUS_OPENAI_MAP[$filterByIndex[self::ID]])) {
                continue;
            }

            $filterKeyFromOpenAi = self::FILTER_IDS_LOTUS_OPENAI_MAP[$filterByIndex[self::ID]];
            $valueFromOpenAi = $this->getValueFromKeyCandidate($filterKeyFromOpenAi, $suggestedFilterFromOpenAi);

            if (empty($valueFromOpenAi)) {
                continue;
            }

            $modifiedOptions = [];

            // particular process for discount filter
            if (strtolower($filterByIndex[self::ID]) == 'discount') {
                $suggestedValue = floor($valueFromOpenAi/10) * 10;
                if ($suggestedValue >= 80) {
                    $suggestedValue = 80;
                }

                foreach ($filterByIndex[self::OPTIONS] as $option) {
                    $rangeValueDiscount = explode('-', $option[self::VALUE]);
                    $firstDiscount = (int) ($rangeValueDiscount[0] ?? 0);

                    if (!$firstDiscount || $firstDiscount != $suggestedValue) {
                        continue;
                    }

                    $option[self::SELECTED] = true;
                    $modifiedOptions[] = $option;
                }
            } else {
                foreach ($filterByIndex[self::OPTIONS] as $option) {
                    // with array values
                    if (is_array($valueFromOpenAi)) {
                        foreach ($valueFromOpenAi as $val) {
                            if ($this->levenshteinDistanceMatrix(strtolower($option[self::LABEL]), strtolower($val))
                                <= (self::WEIGHT_LABEL[$filterKeyFromOpenAi] ?? self::WEIGHT_LABEL[self::DEFAULT])
                            ) {
                                $option[self::SELECTED] = true;
                                $modifiedOptions[] = $option;
                            }
                        }
                        continue;
                    }

                    // with string value
                    if ($this->levenshteinDistanceMatrix(strtolower($option[self::LABEL]), strtolower($valueFromOpenAi))
                        <= (self::WEIGHT_LABEL[$filterKeyFromOpenAi] ?? self::WEIGHT_LABEL[self::DEFAULT])
                    ) {
                        $option[self::SELECTED] = true;
                        $modifiedOptions[] = $option;
                        break;
                    }
                }
            }

            if (! empty($modifiedOptions)) {
                $modifiedFilterByIndex[self::OPTIONS] = $modifiedOptions;
                $modifiedFilter[] = $modifiedFilterByIndex;
            }
        }

        return $modifiedFilter;
    }

    /**
     * @param array $suggestedPriceFromOpenAi
     * @return array
     */
    protected function extractMinMaxPrice(array $suggestedPriceFromOpenAi): array
    {
        $minValue = $suggestedPriceFromOpenAi['price_min_value'] ?? null;
        $maxValue = $suggestedPriceFromOpenAi['price_max_value'] ?? null ;
        return [$minValue, $maxValue];
    }

    /**
     * @param $minValue
     * @param $maxValue
     * @return string
     */
    protected function buildValueOptionForPrice($minValue, $maxValue): string
    {
        $minValue = empty($minValue) ? '*' : $minValue;
        $maxValue = empty($maxValue) ? '*' : $maxValue;

        return sprintf('%s-%s', $minValue, $maxValue);
    }

    /**
     * @param array $suggestedSimpleKeywordFromOpenAi
     * @return string
     */
    protected function extractSimpleKeyword(array $suggestedSimpleKeywordFromOpenAi): string
    {
        return (string) ($suggestedSimpleKeywordFromOpenAi['main_product_name'] ?? '');
    }

        /**
     * @param $filterKey
     * @param $suggestedFilterFromOpenAiMap
     * @return string|array
     */
    protected function getValueFromKeyCandidate($filterKey, $suggestedFilterFromOpenAiMap)
    {
        return $suggestedFilterFromOpenAiMap[$filterKey] ?? '';
    }


    ############################################
    ###### FUNCTIONS TO WORK WITH OPEN AI ######
    ############################################

    /**
     * @param string $content
     * @return array|string[]
     */
    protected function concurrentRequestsToOpenAi(string $content): array
    {
        try {
            $responses = Http::pool(fn(Pool $pool) => [
                $pool->withToken(env('OPENAI_API_KEY'))
                    ->acceptJson()
                    ->post(env('OPENAI_URL'), [
                        'model' => 'gpt-3.5-turbo',
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $this->buildMessageToAskFilterSuggestionFromOpenAi($content)
                            ]
                        ],
                        'temperature' => 0.7
                    ]),
                $pool->withToken(env('OPENAI_API_KEY'))
                    ->acceptJson()
                    ->post(env('OPENAI_URL'), [
                        'model' => 'gpt-3.5-turbo',
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $this->buildMessageToAskSimpleKeywordSuggestionFromOpenAi($content)
                            ]
                        ],
                        'temperature' => 0.7
                    ])
            ]);

            return [
                $this->extractOpenAiMessage($responses[0]->json()),
                $this->extractOpenAiMessage($responses[1]->json())
            ];
        } catch (Throwable $ex) {
            //
        }

        return [ '{}', '{}' ];
    }

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

    /**
     * @param $string1
     * @param $string2
     * @return mixed
     */
    protected function levenshteinDistanceMatrix($string1, $string2) {
        $length1 = mb_strlen($string1, 'UTF-8');
        $length2 = mb_strlen($string2, 'UTF-8');

        // Create the matrix
        $matrix = array_fill(0, $length1 + 1, array_fill(0, $length2 + 1, 0));

        // Initialize the matrix
        for ($i = 0; $i <= $length1; $i++) {
            $matrix[$i][0] = $i;
        }

        for ($j = 0; $j <= $length2; $j++) {
            $matrix[0][$j] = $j;
        }

        // Fill the matrix
        for ($i = 1; $i <= $length1; $i++) {
            for ($j = 1; $j <= $length2; $j++) {
                $cost = ($string1[$i - 1] !== $string2[$j - 1]) ? 1 : 0;

                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,
                    $matrix[$i][$j - 1] + 1,
                    $matrix[$i - 1][$j - 1] + $cost
                );
            }
        }

        // Return the bottom-right cell of the matrix
        return $matrix[$length1][$length2];
    }

}
