<?php

namespace QuantimodoTest\Connectors;

class MoodscopeTest extends ConnectorTestCase
{
    /**
     * Moodscope credentials.
     */
    const CREDENTIALS_USERNAME = 'm@quantimodo.com';
    const CREDENTIALS_PASSWORD = 'B1ggerstaff!';

    /**
     * Test that the connector can connect properly.
     */
    public function testConnect()
    {
        $credentials = [
            'username' => self::CREDENTIALS_USERNAME,
            'password' => self::CREDENTIALS_PASSWORD
        ];

        $response = $this->getconnector()->connect($credentials);

        $this->assertConnectSuccessful($response);
        $this->assertStoredCredentialsMatch($credentials);
    }

    /**
     * Test that a connect gracefully fails when the username field is missing.
     */
    public function testConnectWithMissingUsername()
    {
        $response = $this->getconnector()->connect([
            'password' => self::CREDENTIALS_PASSWORD
        ]);

        $this->assertConnectUnsuccessful($response);
    }

    /**
     * Test that a connect gracefully fails when the password field is missing.
     */
    public function testConnectWithMissingPassword()
    {
        $response = $this->getConnector()->connect([
            'username' => self::CREDENTIALS_USERNAME,
        ]);

        $this->assertConnectUnsuccessful($response);
    }

    /**
     * Test that a connect gracefully fails when credentials are missing.
     */
    public function testConnectWithMissingCredentials()
    {
        $response = $this->getConnector()->connect([]);

        $this->assertConnectUnsuccessful($response);
    }

    /**
     * Test that a connect gracefully fails when given invalid credentials.
     */
    public function testConnectWithInvalidCredentials()
    {
        $response = $this->getConnector()->connect([
            'username' => 'invalid@example.com',
            'password' => 'MyPasswordIsIncrediblySecure!'
        ]);

        $this->assertConnectUnsuccessful($response);
    }

    /**
     * Test that the connector can update properly
     */
    public function testUpdate()
    {
        $this->testConnect();

        $response = $this->getConnector()->update(0);

        // TODO: Implement testUpdate() method.
        $this->markTestIncomplete(
            'testUpdate is not yet implemented for Moodscope.'
        );
    }

    /**
     * @return string The class name of the connector that is tested here.
     */
    protected function getConnectorClassName()
    {
        return 'MoodscopeConnector';
    }
}
