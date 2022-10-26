<?php



class Investment {
	public $debugMode = false;
	public $investmentSharesServiceURL = '';	// Ссылка для запроса данных по акциям
	public $responseFormat = 'json';			// Формат ответа данных по акциям
	public $apiToken = '';						// Токент авторизации для запроса данных по акциям
	private $priceUpdated = 0;
	private $priceMissed = 0;
	private $companyUpdated = 0;
	private $companyMissed = 0;

	private $arrContextOptions = [
		"ssl" => [
			"verify_peer"	   => false,
			"verify_peer_name" => false,
		],
	];


	public function __construct($options = null) {
		if (empty($this->apiToken)) {
			$this->apiToken = getVal('INVESTMENT_SHARES_TOKEN');
		}

		if (empty($this->apiToken)) {
			throw new Exception(getVal('MSG_INVESTMENT_SHARES_TOKEN_NOT_DEFINED'));
		}

		if (empty($this->responseFormat)) {
			$this->responseFormat = 'json';
		}
   	}


	public function getInvestmentSelections()
	{
		$investmentSelections = db_get_array("
			SELECT
				*
			FROM
				".T_PREFIX."module_selections
			WHERE
				lang_id = 1
				AND is_published = 1
		","","","id");

		if (empty($investmentSelections)) {
			return [];
		}

		return $this->convertTickersJsonToArray($investmentSelections);
	}


	private function convertTickersJsonToArray(array $selections)
	{
		foreach ($selections as $key => $selection) {
			$selections[$key]['companies'] = !empty($selection['companies']) ? json_decode($selection['companies'], true) : [];
		}

		return $selections;
	}


	public function updateCompaniesSharesPrices(array $companies, array $investmentShares)
	{
		if (empty($companies) || empty($investmentShares)) {
			addlog(getVal('MSG_TICKERS_UPDATE_ERROR'), false, DEBUG_LOG_FILE);
			return false;
		}

		foreach ($investmentShares as $item) {
			if (empty($item['code'])) {
				$this->companyMissed++;
				addlog(getVal('MSG_INVESTMENT_SHARE_CODE_IS_EMPTY').'. '.getVal('STR_TICKERS'), false, DEBUG_LOG_FILE);
				continue;
			}

			if (empty($item['close']) || $item['close'] == 'NA') {
				$this->companyMissed++;
				addlog(getVal('MSG_INVESTMENT_SHARE_PRICE_IS_EMPTY').'. '.getVal('STR_TICKERS').': '.$item['code'], false, DEBUG_LOG_FILE);
				continue;
			}

			$skip = true;

			foreach ($companies as $key => $company) {
				if (empty($company['ticker']) || ($company['ticker'] != $item['code'] && $company['ticker'].'.US' != $item['code'])) {
					continue;
				}

				$company['price'] = $item['close'];
				$company['currency'] = !empty($company['currency']) ? $company['currency'] : $currencyDefault;
				$companies[$key] = convertShareInfo($company);
				$this->companyUpdated++;
				$skip = false;
			}

			if ($skip) {
				$this->companyMissed++;
				addlog(getVal('MSG_INVESTMENT_SHARE_NOT_FOUND_IN_COMPANIES').'. '.getVal('STR_TICKERS').': '.$item['code'], false, DEBUG_LOG_FILE);
				continue;
			}
		}

		return $companies;
	}


	public function updateSelectionSharesPrices(array $selection)
	{

		if (empty($selection['companies'])) {
			$this->priceMissed += $this->companyMissed + $this->companyUpdated;
			$this->companyUpdated = 0;
			$this->companyMissed = 0;
			addlog(getVal('MSG_TICKERS_UPDATE_ERROR'), false, DEBUG_LOG_FILE);
			return false;
		}

		$companies = json_encode($selection['companies'], JSON_UNESCAPED_UNICODE);
		$resultUpdate = db_query("
			UPDATE
				".T_PREFIX."module_selections
			SET
				companies = '".db_real_escape_string($companies)."'
			WHERE
				id = ".$selection['id']."
		");

		if (!empty($resultUpdate)) {
			$this->priceUpdated += $this->companyUpdated;
			$this->priceMissed += $this->companyMissed;

			if ($this->debugMode) {
				addlog('Цена обновлена: у '.$this->companyUpdated, false, DEBUG_LOG_FILE);
			}
		} else {
			$this->priceMissed += $this->companyMissed + $this->companyUpdated;
			addlog(getVal('MSG_INVESTMENT_SHARE_UPDATE_ERROR'), false, DEBUG_LOG_FILE);
		}

		$this->companyUpdated = 0;
		$this->companyMissed = 0;
	}


	public function getInvestmentIdeas()
	{
		return db_get_array("
			SELECT
				*
			FROM
				".T_PREFIX."module_investment_ideas
			WHERE
				lang_id = 1
				AND is_published = 1
		","","","id") ?: [];
	}


	public function convertTickersFormat(array $items = [], string $field = 'ticker')
	{
		if (empty($items)) {
			addlog(getVal('MSG_ITEMS_FOR_CONVERT_NOT_FOUND'), false, DEBUG_LOG_FILE);
			return [];
		}

		foreach ($items as $key => $item) {
			if (empty($item[$field])) {
				unset($items[$key][$field]);
				continue;
			}

			$items[$key][$field] = $field == 'ticker' ? $this->convertTickerFormat($item[$field]) : $this->convertCodeFormat($item[$field]);

			if (empty($items[$key][$field])) {
				unset($items[$key][$field]);
			}
		}

		return $items;
	}


	public function convertTickerFormat(string $ticker = '') {
		if (empty($ticker)) {
			return '';
		}

		$ticker = trim($ticker, "., \t\n\r\0\v");

		return strtoupper(str_replace(' ', '.', $ticker));
	}


	public function convertCodeFormat(string $code = '') {
		if (empty($ticker)) {
			return '';
		}

		$code = trim($code, "., \t\n\r\0\v");

		return strtoupper($code);
	}


	public function getInvestmentSharesByTickers(string $tickers = '')
	{
		if (empty($tickers)) {
			addlog(getVal('MSG_TICKERS_NOT_FOUND'), false, DEBUG_LOG_FILE);
			return [];
		}

		$url = $this->buildInvestmentSharesURL($tickers);
		$result = file_get_contents($url, false, stream_context_create($this->arrContextOptions));

		if (empty($result)) {
			addlog(getVal('MSG_REQUEST_DATA_NOT_RECEIVED').'. '.getVal('STR_TICKERS').': '.$tickers, false, DEBUG_LOG_FILE);
			$number = count(explode(',', $tickers));
			$this->priceMissed += $number;
			return [];
		}

		return json_decode($result, true);
	}


	private function buildInvestmentSharesURL(string $tickers = '')
	{
		$firstTiecker = $this->getFirstTicker($tickers);
		return $this->investmentSharesServiceURL.$firstTiecker.'/?api_token='.$this->apiToken.'&fmt='.$this->responseFormat.'&s='.$tickers;
	}


	private function getFirstTicker(string $tickers)
	{
		$tickers = explode(',', $tickers);

		if (empty($tickers) || !is_array($tickers)) {
			addlog(getVal('MSG_TICKERS_FORMAT_ERROR').'. '.getVal('STR_TICKERS').': '.$tickers, false, DEBUG_LOG_FILE);
			return '';
		}

		return array_shift($tickers);
	}


	public function updateIdeasPrices(array $investmentIdeas, array $investmentShares)
	{
		if (empty($investmentIdeas) || empty($investmentShares)) {
			addlog(getVal('MSG_TICKERS_UPDATE_ERROR'), false, DEBUG_LOG_FILE);
			return false;
		}

		$tickersID = array_flip(array_unique(array_column($investmentIdeas, 'ticker', 'id')));
		$investmentShares = $this->checkInvestmentShares($investmentShares);

		foreach ($investmentShares as $key => $item) {
			if (empty($tickersID[$item['code']])) {
				$code = explode('.', $item['code']);
				$item['code'] = $code[0];
			}

			if (empty($tickersID[$item['code']])) {
				$this->priceMissed++;
				addlog(getVal('MSG_INVESTMENT_SHARE_NOT_FOUND').'. '.getVal('STR_TICKERS').': '.$item['code'], false, DEBUG_LOG_FILE);
				unset($investmentShares[$key]);
				continue;
			}

			$tickerID = $tickersID[$item['code']];
			$this->updateTickerPrice($investmentIdeas[$tickerID], $item);
		}

		return true;
	}


	private function checkInvestmentShares(array $investmentShares)
	{
		foreach ($investmentShares as $key => $item) {
			if (empty($item) || empty($item['code'])) {
				$this->priceMissed++;
				addlog(getVal('MSG_INVESTMENT_SHARE_IS_EMPTY'), false, DEBUG_LOG_FILE);
				unset($investmentShares[$key]);
			}

			if (empty($item['close']) || $item['close'] == 'NA') {
				$this->priceMissed++;
				addlog(getVal('MSG_INVESTMENT_SHARE_PRICE_IS_EMPTY').'. '.getVal('STR_TICKERS').': '.$item['code'], false, DEBUG_LOG_FILE);
				unset($investmentShares[$key]);
				continue;
			}
		}

		return $investmentShares;
	}


	public function updateTickerPrice(array $investmentIdea, array $item)
	{
		$price = str_replace(',', '.', (float)$item['close']);
		$forecast = ($investmentIdea['target_price'] > 0 && $price > 0) ? round($investmentIdea['target_price'] / $price - 1, 1) : 0;
		$forecast = str_replace(',', '.', (float)$forecast);

		$resultUpdate = db_query("
			UPDATE
				".T_PREFIX."module_investment_ideas
			SET
				price = ".$price.",
				forecast = ".$forecast."
			WHERE
				CONCAT(' ',ticker,' ') LIKE '% ".db_real_escape_string($item['code'])." %'
				OR CONCAT('.',ticker,'.') LIKE '%.".db_real_escape_string($item['code']).".%'
			");

		if (!empty($resultUpdate)) {
			$this->priceUpdated++;

			if ($this->debugMode) {
				addlog('Цена обновлена. '.getVal('STR_TICKERS').': '.$item['code'], false, DEBUG_LOG_FILE);
			}
		} else {
			$this->priceMissed++;
			addlog(getVal('MSG_INVESTMENT_SHARE_UPDATE_ERROR').'. '.getVal('STR_TICKERS').': '.$item['code'], false, DEBUG_LOG_FILE);

			if ($this->debugMode) {
				addlog($message, false, DEBUG_LOG_FILE);
			}
		}
	}


	public function showResult() {
		$message = getVal('MSG_TOTAL_PRICES_UPDATED').' '.$this->priceUpdated."\n";
		$message .= getVal('MSG_TOTAL_PRICE_MISSED').' '.$this->priceMissed." \n";

		echo "<br> \n\n";
		echo $message;

		if ($this->debugMode) {
			addlog($message, false, DEBUG_LOG_FILE);
		}
	}

}
?>
