<?php

namespace App\Http\Controllers;

use App\Models\GeneralFilter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiApiController extends BaseController
{


    const MESSAGE_TO_ASK_FILTER_SUGGESTION_TEMPLATE = 'Extract the category, color, brand, store and price from the content "%s" and return as json format';

    const MESSAGE_TO_ASK_FILTER_PRICE_SUGGESTION_TEMPLATE = 'Extract the content "%s" and only return as following json format {price_min_value: integer, price_max_value: integer}' ;

    const LABEL = 'Label';

    const WIDGET = 'Widget';

    const ID = 'Id';
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

//        $originalFilterFromLotus = (array) $request->input('filter', []);
        $originalFilterFromLotus = GeneralFilter::generateGeneralFilter();

        $customerContent = $request->input('content', '');

        // Make request to Open AI with customerContent
        $result = $this->callOpenAi(
            $this->buildMessageToAskFilterSuggestionFromOpenAi($customerContent)
        );

        // Extract answer from Open AI
        $message = $this->extractOpenAiMessage($result);

//        $message = "{\n  \"category\": \"shoes\",\n  \"color\": \"red\",\n  \"brand\": \"Adidas\",\n  \"store\": \"unknown\",\n  \"price\": \"less than 870 usd\"\n}";
        $suggestedFilterFromOpenAi = @json_decode($message, true);

        // Rebuild the filter with suggestion from Open AI
        $filter = $this->rebuildFilter($originalFilterFromLotus, $suggestedFilterFromOpenAi, $customerContent);

        return response()->json([
            'data' => $filter
        ]);
    }



    public function buildMap(array $data)
    {
        $map = [];
        foreach ($data as $key => $value) {
            $map[$key] = $value;
        }
        return $map;
    }

    public function levenshteinDistanceMatrix($string1, $string2) {
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

    const OPTIONS = 'Options';
    const SELECTED = 'Selected';
    const VALUE = 'Value';
    const RESULT_COUNT= 'ResultCount';
    /**
     * @param array $originalFilterFromLotus
     * @param array $suggestedFilterFromOpenAiMap
     * @return array
     */
    protected function rebuildFilter(array $originalFilterFromLotus, array $suggestedFilterFromOpenAi, string $customerContent): array
    {
        $modifiedFilter = [];

        foreach ($originalFilterFromLotus as $filterByIndex) {
            $modifiedFilterByIndex = $filterByIndex;

            if (!$valueFromUser = $this->getValueFromKeyCandidate(strtolower($filterByIndex[self::LABEL]), $suggestedFilterFromOpenAi)) {
                continue;
            }


            if ($filterByIndex[self::LABEL] === 'Price') {
                $widgetObject = $filterByIndex[self::WIDGET];
                list($minValue, $maxValue) = $this->getMinMaxSelectedByOpenAI($customerContent);
                $widgetObject['MinSelected'] = $minValue;
                $widgetObject['MaxSelected'] = $maxValue;

                $modifiedFilterByIndex[self::WIDGET] = $widgetObject;
                $modifiedFilter[] = $modifiedFilterByIndex;
                continue;
            }

            $listOptions = $filterByIndex[self::OPTIONS];
            $modifiedOptions = [];

            foreach ($listOptions as $option) {
                $modifiedOption = $option;

                if ($this->levenshteinDistanceMatrix(strtolower($option[self::LABEL]), strtolower($valueFromUser)) <= 2) {
                    $modifiedOption[self::SELECTED] = true;
                }

                $modifiedOptions[] = $modifiedOption;
            }

            $modifiedFilterByIndex[self::OPTIONS] = $modifiedOptions;
            $modifiedFilter[] = $modifiedFilterByIndex;
        }

        return $modifiedFilter;
    }

    public function getMinMaxSelectedByOpenAI($queryFromUser)
    {
        $result = $this->callOpenAi(
            $this->buildMessageToAskFilterPriceSuggestionFromOpenAi($queryFromUser)
        );
        $message = $this->extractOpenAiMessage($result);
        $message = @json_decode($message, true);
        if($message){
            $minValue = $message['price_min_value'] ?? 0;
            $maxValue = max($message['price_max_value'] ?? 0, $minValue + 1) ;
            return [$minValue, $maxValue];
        }

        return [0, 200];

    }

    public function getValueFromKeyCandidate($string1, $suggestedFilterFromOpenAiMap)
    {
        if(!array_key_exists($string1, $suggestedFilterFromOpenAiMap)){
            return "";
        }
        return $suggestedFilterFromOpenAiMap[$string1];
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
