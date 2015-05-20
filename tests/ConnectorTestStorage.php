<?php

namespace QuantimodoTest\Connectors;

use PDO;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\QuantimodoStorage;
use Quantimodo\PhpConnect\Model\Storage;
use stdClass;

/**
 * Storage class used to test a Connector's functionality.
 *
 * @package QuantimodoTest\Connectors
 */
class ConnectorTestStorage extends QuantimodoStorage
{
    /**
     * @var array Associative array [userId][connectorId] holding credentials supplied by a connector.
     */
    private $credentials;

    /**
     * @var array Associative array [userId][connectorId] holding booleans indicating whether a connector is connected.
     */
    private $connections;

    function __construct()
    {
        parent::__construct();

        $this->credentials = [];
        $this->connections = [];
    }

    public function init()
    {
        // Nothing needs to be done here.
    }

    public function storeUpdateResult($userId, Connector $connector, $numMeasurements, $error)
    {
        if ($error == null) {
            $error = "No error";
        }

        echo " [INFO] Storing update result for user " . $userId . "\n";
        echo "        User: " . $userId . " connector: " . $connector->name . " numMeasurements: " . $numMeasurements . " error: " . $error;
    }

    public function storeCredentials($userId, $connectorId, $credentials)
    {
        $this->credentials[$userId][$connectorId] = $credentials;
    }

    public function removeCredentials($userId, $connectorId)
    {
        unset($this->credentials[$userId][$connectorId]);
    }

    public function getCredentials($userId, $connectorId)
    {
        if (isset($this->credentials[$userId][$connectorId])) {
            return $this->credentials[$userId][$connectorId];
        } else {
            return [];
        }
    }

    public function hasCredentials($userId, $connectorId)
    {
        return isset($this->credentials[$userId][$connectorId]);
    }

    public function addConnection($userId, $connectorId)
    {
        $this->connections[$userId][$connectorId] = true;
    }

    public function removeConnection($userId, $connectorId)
    {
        unset($this->connections[$userId][$connectorId]);
    }

    public function hasConnection($userId, $connectorId)
    {
        return isset($this->connections[$userId][$connectorId]);
    }

    public function storeMeasurements($userId, array $measurementSets)
    {
        //TODO validate measurements
        echo " [INFO] Storing measurements for user " . $userId . "\n";
        $timestampUpperLimit = round(microtime(true)) + 604800;    // Set upper limit to a week in the future
        $timestampLowerLimit = 946684800;                        // Lower limit is equal to 01/01/2000
        $modifiedVariables = [];
        $numMeasurements = 0;
        foreach ($measurementSets as $measurementSet) {
            if (get_class($measurementSet) !== 'MeasurementSet') {
                echo " [ERROR] Not a valid measurement set\n";
                break;
            }
            if (!isset($measurementSet->unitName)) {
                echo " [ERROR] Missing unit in measurementSet\n";
                break;
            }
            if (!isset($measurementSet->sourceName)) {
                echo " [ERROR] Missing source in measurementSet\n";
                break;
            }
            if (!isset($measurementSet->variableName)) {
                echo " [ERROR] Missing variable in measurementSet\n";
                break;
            }
            if (!isset($measurementSet->categoryName)) {
                echo " [ERROR] Missing category in measurementSet\n";
                break;
            }
            if (!isset($measurementSet->combinationOperation)) {
                echo " [ERROR] Missing combination operation in measurementSet\n";
                break;
            }
            if ($measurementSet->combinationOperation != "SUM" && $measurementSet->combinationOperation != "MEAN") {
                echo " [ERROR] Invalid combination operation " . $measurementSet->combinationOperation . ", Must be 'SUM' or 'MEAN'\n";
                break;
            }
            $numNewMeasurements = 0;
            foreach ($measurementSet->measurements as $measurement) {
                if (gettype($measurement) != 'object') {
                    echo " [ERROR] Not a valid measurement object (type = " . gettype($measurement) . ")\n";
                    break;
                }
                if (get_class($measurement) !== 'Measurement') {
                    echo " [ERROR] Not a valid measurement object (class = " . get_class($measurement) . ")\n";
                    break;
                }
                // If this measurement is more than a week in the future throw an error
                if (!is_numeric($measurement->timestamp)) {
                    echo " [ERROR] timestamp is not a number: " . $measurement->timestamp . "\n";
                    break;
                }
                if ($measurement->timestamp > $timestampUpperLimit) {
                    echo " [ERROR] timestamp too far in future: " . $measurement->timestamp . "\n";
                    break;
                }
                if ($measurement->timestamp < $timestampLowerLimit) {
                    echo " [ERROR] timestamp too far in past: " . $measurement->timestamp . "\n";
                    break;
                }
                if (!isset($measurement->value) || !is_numeric($measurement->value)) {
                    echo " [ERROR] Missing or invalid value for measurement\n";
                    break;
                }
                if (isset($measurement->duration) && !is_numeric($measurement->duration)) {
                    echo " [ERROR] Invalid duration for measurement\n";
                    break;
                }
                $numNewMeasurements++;
            }
            if ($numNewMeasurements > 0) {
                $modifiedVariables[] = $measurementSet->variableName;
                $numMeasurements += $numNewMeasurements;
            }
            echo " [INFO] Done verifying measurementset with " . count($measurementSet->measurements) . " measurements: \n";
            echo "        Var: " . $measurementSet->variableName . "\n";
            echo "        Src: " . $measurementSet->sourceName . "\n";
            echo "        Uni: " . $measurementSet->unitName . "\n";
            echo "        Cat: " . $measurementSet->categoryName . "\n";
            echo "        Com: " . $measurementSet->combinationOperation . "\n";
            echo " [INFO] Please make sure the unit and category exist in production before deploying this connector\n";
        }

        return [
            'modifiedVariables' => $modifiedVariables,
            'num' => $numMeasurements,
            'errors' => empty($errors) ? null : implode("\n", $errors)
        ];
    }
}
