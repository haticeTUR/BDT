<?php
namespace axenox\BDT\Behat\Contexts\JEasyUI;

use Behat\Behat\Context\Context;
use Behat\MinkExtension\Context\MinkContext;
use PHPUnit\Framework\Assert;


/**
 * Defines application features from the specific context.
 */
class FeatureContext extends MinkContext implements Context
{
    /**
     * Initializes context.
     *
     * Every scenario gets its own context instance.
     * You can also pass arbitrary arguments to the
     * context constructor through behat.yml.
     */
    private $loginPage;

    public function __construct()
    {
    }
    //******************************************** */

    

    //************************************** */



      /**
     * @Given I navigate to the login page
     */
    public function iNavigateToTheLoginPage()
    {

        $this->visitPath('/index.html');

        // Geçerli URL'yi al ve ekrana yazdır
        $currentUrl = $this->getSession()->getCurrentUrl();
        echo 'Current URL: ' . $currentUrl;

        $this->loginPage = new LoginPage($this->getSession());
        
    }

    /**
     * @When I enter valid credentials
     */
    public function iEnterValidCredentials()
    {
        //$username = getenv('USERNAME');
        //$password = getenv('PASSWORD');
 
        $this->loginPage->enterUsername('admin');
        $this->loginPage->enterPassword('admin');
        $this->loginPage->clickLoginButton();
    }

    /**
     * @Then I should be logged in successfully
     */
    public function iShouldBeLoggedInSuccessfully()
    {
        $expectedUrl = 'http://localhost/exface/exface/#';
        $currentUrl = $this->getSession()->getCurrentUrl();
        Assert::assertEquals($expectedUrl, $currentUrl, "URL did not change after login, login failed");
    }
}
