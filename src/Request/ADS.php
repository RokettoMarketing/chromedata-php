<?php

namespace Darinrandal\ChromeData\Request;

use Darinrandal\ChromeData\Adapter\Adapter;
use Darinrandal\ChromeData\Response\ADSResponse;
use GuzzleHttp\Client;
use function GuzzleHttp\Promise\each_limit;
use function GuzzleHttp\Promise\iter_for;
use GuzzleHttp\Promise\PromiseInterface;
use Meng\AsyncSoap\Guzzle\Factory;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;

class ADS extends Request
{
    /**
     * Automotive Description Service Endpoint
     */
    const ADS_ENDPOINT = 'http://services.chromedata.com/Description/7b?wsdl';

    /**
     * @var array
     */
    protected $parameters = [];

    /**
     * Holds the handle to the SoapClientInterface
     *
     * @var \Meng\AsyncSoap\SoapClientInterface
     */
    protected $client;

    /**
     * ADS constructor.
     * @param Adapter $adapter
     */
    public function __construct(Adapter $adapter)
    {
        parent::__construct($adapter);

        $factory = new Factory();
        $this->client = $factory->create( new Client(), new StreamFactory(), new RequestFactory(), static::ADS_ENDPOINT);


    }

    /**
     * Performs a Automotive Description Service request to ChromeData by VIN
     * Returns an ADSResponse object to access and retrieve VIN data.
     *
     * Pass in an array of vehicle data to increase the change of an exact-match style. Uses ChromeData Parameters
     * from docs: trimName, manufacturerModelCode, wheelBase, OEMOptionCode, exteriorColorName, interiorColorName,
     * styleName, reducingStyleID, reducingAcode
     *
     * @param string $vin
     * @param array $parameters
     * @return ADSResponse
     */
    public function byVin(
        string $vin,
        array $parameters = []
    ): ADSResponse
    {
        $promise = $this->byVinAsync($vin, $parameters);

        return new ADSResponse($promise->wait(), $parameters);
    }

    /**
     * Performs an ADS request to ChromeData by VIN returning a Promise for async. Use with $this->pool()
     *
     * @param string $vin
     * @param array $parameters
     * @return PromiseInterface
     */
    public function byVinAsync(
        string $vin,
        array $parameters = []
    ): PromiseInterface
    {
        return $this->client->describeVehicle($this->buildParameterArray($vin, $parameters));
    }

    /**
     * Pools a set of async requests together at a specified concurrency and waits for completion.
     * $requests can be a generator, array, or iterable object
     *
     * $fulfilled receives an ADSResponse object as the first parameter
     *
     * @param $requests
     * @param callable $fulfilled
     * @param callable $rejected
     * @param int $concurrency
     */
    public function pool(
        $requests,
        callable $fulfilled,
        callable $rejected,
        int $concurrency = 15
    ): void
    {
        each_limit(iter_for($requests), $concurrency, function ($response, $idx, $aggregate) use ($fulfilled) {
            call_user_func_array($fulfilled, [
                new ADSResponse($response),
                $idx,
                $aggregate
            ]);
        }, $rejected)->wait();
    }

    /**
     * @return \Meng\AsyncSoap\SoapClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Verifies a VIN checksum is valid
     *
     * @param string $vin
     * @return bool
     */
    protected function validateVin(string $vin): bool
    {
        $vin = strtolower($vin);

        if (!preg_match('/^[^\Wioq]{17}$/', $vin)) {
            return false;
        }

        $weights = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

        $transliterations = [
            'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6, 'g' => 7, 'h' => 8,
            'j' => 1, 'k' => 2, 'l' => 3, 'm' => 4, 'n' => 5, 'p' => 7, 'r' => 9, 's' => 2,
            't' => 3, 'u' => 4, 'v' => 5, 'w' => 6, 'x' => 7, 'y' => 8, 'z' => 9,
        ];

        $sum = 0;
        $vinLength = strlen($vin);

        for ($i = 0; $i < $vinLength; $i++) {
            if (!is_numeric($vin[$i])) {
                $sum += $transliterations[$vin[$i]] * $weights[$i];
            } else {
                $sum += $vin[$i] * $weights[$i];
            }
        }

        $checkDigit = $sum % 11;

        return ($checkDigit === 10 ? 'x' : $checkDigit) == $vin[8];
    }

    /**
     * Add color matched photos in response
     *
     * @return $this
     */
    public function includeColorMatchedPhotos()
    {
        $this->parameters['includeMediaGallery'] = 'ColorMatch';

        return $this;
    }

    /**
     * Add available equipment in response
     *
     * @return $this
     */
    public function includeAvailableEquipment()
    {
        $this->parameters['switch'][] = 'ShowAvailableEquipment';

        return $this;
    }

    /**
     * Include extended descriptions Chrome options
     *
     * @return $this
     */
    public function includeExtendedDescriptions()
    {
        $this->parameters['switch'][] = 'ShowExtendedDescriptions';

        return $this;
    }

    /**
     * Exclude fleet vehicle from the request
     *
     * @return $this
     */
    public function excludeFleet()
    {
        $this->parameters['vehicleProcessMode'] = 'ExcludeFleetOnly';
        $this->parameters['optionsProcessMode'] = 'ExcludeFleetOnly';

        return $this;
    }

    /**
     * Merges auth data, custom parameters, and set global parameters on the request data
     *
     * @param string $vin
     * @param array $parameters
     * @return array
     */
    protected function buildParameterArray(string $vin, array $parameters = [])
    {
        return array_merge($this->parameters, [
            'accountInfo' => [
                'number' => $this->adapter->getAuth()->getAccountNumber(),
                'secret' => $this->adapter->getAuth()->getAccountSecret(),
                'country' => 'US',
                'language' => 'en',
            ],
            'vin' => $vin,
            'switch' => [
                'ShowExtendedDescriptions',
                'ShowConsumerInformation',
                'ShowExtendedTechnicalSpecifications',
                'IncludeDefinitions',
                'ShowAvailableEquipment',
            ],
        ], $parameters);
    }
}
