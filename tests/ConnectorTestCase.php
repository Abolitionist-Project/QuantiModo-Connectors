<?php

namespace QuantimodoTest\Connectors;

use PHPUnit_Framework_TestCase;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\ConnectionManager;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\CredentialsManager;
use Quantimodo\PhpConnect\PhpConnect;
use ReflectionClass;

abstract class ConnectorTestCase extends PHPUnit_Framework_TestCase
{
    const CONNECTORS_NAMESPACE = 'Quantimodo\\Connectors';

    /**
     * @var CredentialsManager The CredentialsManager to use.
     */
    private $credentialsManager;

    /**
     * @var CredentialsManager The ConnectionManager to use.
     */
    private $connectionManager;

    /**
     * @var Connector The connector being tested.
     */
    private $connector;

    /**
     * Test that the connector can connect properly.
     */
    abstract public function testConnect();

    /**
     * Test that the connector can update properly
     */
    abstract public function testUpdate();

    /**
     * @return string The class name of the connector that is tested here.
     */
    abstract protected function getConnectorClassName();

    /**
     * @return Connector The connector that is being tested.
     */
    public function getConnector()
    {
        return $this->connector;
    }

    protected function setUp()
    {
        // Write dummy IDs to PhpConnect so that storage doesn't break.
        PhpConnect::$currentConnectorId = 1;
        PhpConnect::$currentUserId = 1;

        $storage = new ConnectorTestStorage();
        $this->credentialsManager = new CredentialsManager($storage, PhpConnect::$currentConnectorId);
        $this->connectionManager = new ConnectionManager($storage, PhpConnect::$currentConnectorId);

        $reflectionClass = new ReflectionClass(self::CONNECTORS_NAMESPACE . '\\' . $this->getConnectorClassName());
        $this->connector = $reflectionClass->newInstanceArgs([$this->connectionManager, $this->credentialsManager, '']);
    }

    protected function tearDown()
    {
        $this->credentialsManager->remove();
        $this->connectionManager->remove();
    }

    /**
     * Assert the given $responseMessage indicates a 'connect' request was successful.
     *
     * @param ResponseMessage $responseMessage The ResponseMessage received from a 'connect' request.
     */
    public function assertConnectSuccessful(ResponseMessage $responseMessage)
    {
        $this->assertInstanceOf('Quantimodo\Messaging\Messages\ResponseMessage', $responseMessage, 'The given response is not an instance of ResponseMessage');
        $this->assertFalse(is_subclass_of($responseMessage, 'Quantimodo\Messaging\Messages\ResponseMessage'), 'The given response is a subclass of ResponseMessage. ResponseMessage itself expected, but got:' . "\n" . print_r($responseMessage, true));
    }

    /**
     * Assert the given $responseMessage indicates a 'connect' request was unsuccessful.
     *
     * @param ResponseMessage $responseMessage The ResponseMessage received from a 'connect' request.
     */
    public function assertConnectUnsuccessful(ResponseMessage $responseMessage)
    {
        $this->assertInstanceOf('Quantimodo\Messaging\Messages\ErrorResponseMessage', $responseMessage, 'The given response is not an instance of ErrorResponseMessage, instead got:' . "\n" . print_r($responseMessage, true));
        $this->assertStoredCredentialsEmpty();
    }

    /**
     * Assert that there are no stored credentials.
     */
    public function assertStoredCredentialsEmpty()
    {
        $storedCredentials = $this->credentialsManager->get();

        $this->assertEmpty($storedCredentials);
    }

    /**
     * Assert that the stored credentials exactly match the expected ones.
     *
     * @param array $expectedCredentials The credentials we expect.
     */
    public function assertStoredCredentialsMatch(array $expectedCredentials)
    {
        $storedCredentials = $this->credentialsManager->get();

        $this->assertSame(
            array_diff_assoc($expectedCredentials, $storedCredentials),
            array_diff_assoc($storedCredentials, $expectedCredentials)
        );
    }

    /**
     * Assert that the stored credentials contain exactly the same keys as the expected ones.
     *
     * @param array $expectedCredentials The credentials we expect.
     */
    public function assertStoredCredentialsKeysMatch(array $expectedCredentials)
    {
        $storedCredentials = $this->credentialsManager->get();

        $this->assertSame(
            array_keys($expectedCredentials, $storedCredentials),
            array_keys($storedCredentials, $expectedCredentials)
        );

        // Assert that the credentials have values.
        foreach($storedCredentials as $storedCredential) {
            $this->assertNotEmpty($storedCredential);
        }
    }
}
