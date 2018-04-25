<?php

namespace Darinrandal\ChromeData\Request;

use Darinrandal\ChromeData\Adapter\Adapter;
use Darinrandal\ChromeData\Response\ADSResponse;

class ADS extends Request
{
    /**
     * Automotive Description Service Endpoint
     */
    const ADS_ENDPOINT = 'http://services.chromedata.com/Description/7b?wsdl';

    protected $parameters = [];

    protected $client;

    public function __construct(Adapter $adapter)
    {
        parent::__construct($adapter);

        $this->client = new \SoapClient(static::ADS_ENDPOINT, [
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'user_agent' => 'Remora ADS Fetcher',
        ]);
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
     * @throws \Darinrandal\ChromeData\Response\ResponseDecodeException
     * @throws \HttpResponseException
     */
    public function byVIN(
        string $vin,
        array $parameters = []
    ): ADSResponse
    {
        if (!$this->validateVIN($vin)) {
            throw new \InvalidArgumentException('VIN doesn\'t pass checksum validation: ' . $vin);
        }

        $this->parameters = array_merge($this->parameters, $parameters);

        $response = $this->dispatchRequest($vin);

//        var_dump($response);die;

        return new ADSResponse($response, $parameters);
    }

    /**
     * Returns the formatted XML after substituting VIN, Trim, Wheelbase, and credentials from the Auth adapter
     *
     * @param string $vin
     * @return string
     */
    protected function dispatchRequest(string $vin)
    {
        return $this->client->describeVehicle($this->buildParameterArray($vin));
    }

    public function getSoapClient()
    {
        return $this->client;
    }

    /**
     * Verifies a VIN checksum is valid
     *
     * @param string $vin
     * @return bool
     */
    protected function validateVIN(string $vin): bool
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
            if (!is_numeric($vin{$i})) {
                $sum += $transliterations[$vin{$i}] * $weights[$i];
            } else {
                $sum += $vin{$i} * $weights[$i];
            }
        }

        $checkDigit = $sum % 11;

        return ($checkDigit === 10 ? 'x' : $checkDigit) == $vin{8};
    }

    public function includeColorMatchedPhotos()
    {
        $this->parameters['includeMediaGallery'] = 'ColorMatch';

        return $this;
    }

    public function includeAvailableEquipment()
    {
        $this->parameters['switch'][] = 'ShowAvailableEquipment';

        return $this;
    }

    public function includeExtendedDescriptions()
    {
        $this->parameters['switch'][] = 'ShowExtendedDescriptions';

        return $this;
    }

    public function excludeFleet()
    {
        $this->parameters['vehicleProcessMode'] = 'ExcludeFleetOnly';
        $this->parameters['optionsProcessMode'] = 'ExcludeFleetOnly';

        return $this;
    }

    protected function buildParameterArray(string $vin)
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
            ],
        ]);
    }
}
