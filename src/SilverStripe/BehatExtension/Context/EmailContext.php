<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
	Behat\Behat\Context\TranslatedContextInterface,
	Behat\Behat\Context\Context,
	Behat\Behat\Context\SnippetAcceptingContext,
    Behat\Behat\Tester,
    Behat\Behat\EventDispatcher\Event,
    Behat\Behat\Tester\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
Behat\Gherkin\Node\TableNode;
use Symfony\Component\DomCrawler\Crawler;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Context used to define steps related to email sending.
 */
class EmailContext implements Context, SnippetAcceptingContext
{
    protected $context;

    protected $mailer;

    /**
     * Stored to simplify later assertions
     */
    protected $lastMatchedEmail;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        // Initialize your context here
        $this->context = $parameters;
    }

    /**
     * Get Mink session from MinkContext
     */
    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @BeforeScenario
     */
    public function before(ScenarioEvent $event)
    {
        // Also set through the 'supportbehat' extension
        // to ensure its available both in CLI execution and the tested browser session
        $this->mailer = new \SilverStripe\BehatExtension\Utility\TestMailer();
        \Email::set_mailer($this->mailer);
        \Config::inst()->update("Email","send_all_emails_to", null);
    }

    /**
     * @Given /^there should (not |)be an email (to|from) "([^"]*)"$/
     */
    public function thereIsAnEmailFromTo($negate, $direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        if(trim($negate)) {
            assertNull($match);
        } else {
            assertNotNull($match);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * @Given /^there should (not |)be an email (to|from) "([^"]*)" titled "([^"]*)"$/
     */
    public function thereIsAnEmailFromToTitled($negate, $direction, $email, $subject)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from, $subject);
        $allMails = $this->mailer->findEmails($to, $from);
        $allTitles = $allMails ? '"' . implode('","', array_map(function($email) {return $email->Subject;}, $allMails)) . '"' : null;
        if(trim($negate)) {
            assertNull($match);
        } else {
            $msg = sprintf(
                'Could not find email %s "%s" titled "%s".',
                $direction,
                $email,
                $subject
            );
            if($allTitles) {
                $msg .= ' Existing emails: ' . $allTitles;
            }
            assertNotNull($match,$msg);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * Example: Given the email should contain "Thank you for registering!".
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     * 
	 * @Given /^the email should (not |)contain "([^"]*)"$/
	 */
	public function thereTheEmailContains($negate, $content)
	{
		if(!$this->lastMatchedEmail) {
			throw new \LogicException('No matched email found from previous step');
		}

		$email = $this->lastMatchedEmail;
		$emailContent = null;
		if($email->Content) {
			$emailContent = $email->Content;
		} else {
			$emailContent = $email->PlainContent;
		}

		if(trim($negate)) {
			assertNotContains($content, $emailContent);
		} else {
			assertContains($content, $emailContent);
		}
	}

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)"$/
     */
    public function iGoToInTheEmailTo($linkSelector, $direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        assertNotNull($match);

        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);
        
        return new Step\When(sprintf('I go to "%s"', $link));
    }

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)" titled "([^"]*)"$/
     */
    public function iGoToInTheEmailToTitled($linkSelector, $direction, $email, $title)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from, $title);
        assertNotNull($match);

        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);
        return new Step\When(sprintf('I go to "%s"', $link));
    }
    
    /**
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     * 
     * @When /^I click on the "([^"]*)" link in the email"$/
     */
    public function iGoToInTheEmail($linkSelector)
    {
        if(!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $match = $this->lastMatchedEmail;
        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);

        return new Step\When(sprintf('I go to "%s"', $link));
    }

    /**
     * @Given /^I clear all emails$/
     */
    public function iClearAllEmails()
    {
        $this->lastMatchedEmail = null;
        return $this->mailer->clearEmails();
    }

	/**
	 * Example: Then the email should contain the following data:
	 * | row1 |
	 * | row2 |
	 * Assumes an email has been identified by a previous step.
	 * @Then /^the email should (not |)contain the following data:$/
	 */
	public function theEmailContainFollowingData($negate, TableNode $table) {
		if(!$this->lastMatchedEmail) {
			throw new \LogicException('No matched email found from previous step');
		}

		$email = $this->lastMatchedEmail;
		$emailContent = null;
		if($email->Content) {
			$emailContent = $email->Content;
		} else {
			$emailContent = $email->PlainContent;
		}
		// Convert html content to plain text
		$emailContent = strip_tags($emailContent);
		$rows = $table->getRows();
		
		// For "should not contain"
		if(trim($negate)) {
			foreach($rows as $row) {
				assertNotContains($row[0], $emailContent);
			}
		} else {
			foreach($rows as $row) {
				assertContains($row[0], $emailContent);
			}
		}
	}
}
