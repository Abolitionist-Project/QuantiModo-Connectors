<?php

namespace QuantimodoTest\Connectors;

class MyFitnessPalTest extends ConnectorTestCase
{
    /**
     * MyFitnessPal credentials.
     */
    const CREDENTIALS_USERNAME = 'mikesinn';
    const CREDENTIALS_PASSWORD = 'B1ggerstaff!';

    /**
     * Test that the connector can connect properly.
     */
    public function testConnect()
    {
/*        $credentials = [
            'username' => self::CREDENTIALS_USERNAME,
            'password' => self::CREDENTIALS_PASSWORD
        ];

        $response = $this->getconnector()->connect($credentials);

        $this->assertConnectSuccessful($response);
        $this->assertStoredCredentialsMatch($credentials);*/
    }

    public function testConnectWithMissingUsername()
    {
/*        $response = $this->getconnector()->connect([
            'password' => self::CREDENTIALS_PASSWORD
        ]);

        $this->assertConnectUnsuccessful($response);*/
    }

    public function testConnectWithMissingPassword()
    {
/*        $response = $this->getConnector()->connect([
            'username' => self::CREDENTIALS_USERNAME,
        ]);

        $this->assertConnectUnsuccessful($response);*/
    }

    public function testConnectWithMissingCredentials()
    {
/*        $response = $this->getConnector()->connect([]);

        $this->assertConnectUnsuccessful($response);*/
    }

    public function testConnectWithInvalidCredentials()
    {
/*        $response = $this->getConnector()->connect([
            'username' => 'invalid@example.com',
            'password' => 'MyPasswordIsIncrediblySecure!'
        ]);

        $this->assertConnectUnsuccessful($response);*/
    }

    /**
     * Test that the connector can update properly
     */
    public function testUpdate()
    {
/*        $this->testConnect();

        $response = $this->getConnector()->update(0);*/

        // TODO: Implement testUpdate() method.
        $this->markTestIncomplete(
            'testUpdate is not yet implemented for MyFitnessPal.'
        );
    }

    /**
     * @return string The class name of the connector that is tested here.
     */
    protected function getConnectorClassName()
    {
        return 'MyFitnessPalConnector';
    }
}
