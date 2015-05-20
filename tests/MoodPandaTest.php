<?php

namespace QuantimodoTest\Connectors;

class MoodPandaConnector extends ConnectorTestCase
{
    /**
     * MoodPanda credentials.
     * pw: B1ggerstaff!
     */
    const CREDENTIALS_USERNAME = 'm@mikesinn.com';

    /**
     * Test that the connector can connect properly.
     */
    public function testConnect()
    {
        $credentials = [
            'email' => self::CREDENTIALS_USERNAME,
        ];

        $response = $this->getConnector()->connect($credentials);

        $this->assertConnectSuccessful($response);
        $this->assertStoredCredentialsMatch([
            'userId' => 7566412 // The expected MoodPanda user ID.
        ]);
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
     * Test that a connect gracefully fails when supplied with invalid credentials.
     */
    public function testConnectWithInvalidCredentials()
    {
        $response = $this->getConnector()->connect([
            'email' => 'invalid@example.com'
        ]);

        $this->assertConnectUnsuccessful($response);
    }

    /**
     * Test that the connector can update properly.
     */
    public function testUpdate()
    {
        $this->testConnect();

        $response = $this->getConnector()->update(0);

        // TODO: Implement testUpdate() method.
        $this->markTestIncomplete(
            'testUpdate is not yet implemented for MoodPanda.'
        );
    }

    /**
     * @return string The class name of the connector that is tested here.
     */
    protected function getConnectorClassName()
    {
        return 'MoodPandaConnector';
    }
}
