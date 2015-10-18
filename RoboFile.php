<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * Download robo.phar from http://robo.li/robo.phar and type in the root of the repo: $ php robo.phar
 * Or do: $ composer update, and afterwards you will be able to execute robo like $ php vendor/bin/robo
 *
 * @see  http://robo.li/
 */
require_once 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/**
 * Class RoboFile
 *
 * @since  1.5
 */
class RoboFile extends \Robo\Tasks
{
	// Load tasks from composer, see composer.json
	use \redcomponent\robo\loadTasks;

	/**
	 * Current RoboFile version
	 */
	private $version = '1.4';

	/**
	 * Downloads and prepares a Joomla CMS site for testing
	 *
	 * @return mixed
	 */
	public function prepareSiteForSystemTests()
	{
		// Get Joomla Clean Testing sites
		if (is_dir('tests/joomla-cms3'))
		{
			$this->taskDeleteDir('tests/joomla-cms3')->run();
		}

		$this->_exec('git clone -b staging --single-branch --depth 1 https://github.com/joomla/joomla-cms.git tests/joomla-cms3');
		$this->say('Joomla CMS site created at tests/joomla-cms3');
	}

	/**
	 * Executes Selenium System Tests in your machine
	 *
	 * @param   array  $options  Use -h to see available options
	 *
	 * @return mixed
	 */
	public function runTest($options = [
		'test'         => null,
		'suite'         => 'acceptance',
		'selenium_path' => null
	])
	{
		if (!$options['selenium_path'])
		{
			$this->getSelenium();
		}

		$this->getComposer();

		$this->taskComposerInstall()->run();

		$this->runSelenium($options['selenium_path']);

		$this->taskWaitForSeleniumStandaloneServer()
		     ->run()
		     ->stopOnFail();

		// Make sure to Run the Build Command to Generate AcceptanceTester
		$this->_exec("vendor/bin/codecept build");

		if (!$options['test'])
		{
			$this->say('Available tests in the system:');

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					'tests/' . $options['suite'],
					RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST);

			$tests = array();

			$iterator->rewind();
			$i = 1;

			while ($iterator->valid())
			{
				if (strripos($iterator->getSubPathName(), 'cept.php')
					|| strripos($iterator->getSubPathName(), 'cest.php'))
				{
					$this->say('[' . $i . '] ' . $iterator->getSubPathName());
					$tests[$i] = $iterator->getSubPathName();
					$i++;
				}

				$iterator->next();
			}

			$this->say('');
			$testNumber     = $this->ask('Type the number of the test  in the list that you want to run...');
			$options['test'] = $tests[$testNumber];
		}

		$pathToTestFile = 'tests/' . $options['suite'] . '/' . $options['test'];

		$this->taskCodecept()
		     ->test($pathToTestFile)
		     ->arg('--steps')
		     ->arg('--debug')
		     ->run()
		     ->stopOnFail();

		$this->killSelenium();
	}

	/**
	 * Function to Run tests in a Group
	 *
	 * @param   array  $options             Array of options
	 * @param   boool  $excludePreparation  Exclude preparation, including selenium scripts
	 *
	 * @return void
	 */
	public function runTests($excludePreparation = false, $options = ['selenium_path' => null])
	{
		if (!$excludePreparation)
		{
			$this->prepareSiteForSystemTests();

			if (!$options['selenium_path'])
			{
				$this->getSelenium();
			}

			$this->getComposer();

			$this->taskComposerInstall()->run();

			$this->runSelenium($options['selenium_path']);
		}

		$this->taskWaitForSeleniumStandaloneServer()
			->run()
			->stopOnFail();

		// Make sure to Run the Build Command to Generate AcceptanceTester
		$this->_exec("vendor/bin/codecept build");

		$this->taskCodecept()
			->arg('--steps')
			->arg('--debug')
			->arg('--tap')
			->arg('--fail-fast')
			->arg('tests/acceptance/install/')
			->run()
			->stopOnFail();

		$this->taskCodecept()
			->arg('--steps')
			->arg('--debug')
			->arg('--tap')
			->arg('--fail-fast')
			->arg('tests/acceptance/administrator/')
			->run()
			->stopOnFail();

		$this->taskCodecept()
			->arg('--steps')
			->arg('--debug')
			->arg('--tap')
			->arg('--fail-fast')
			->arg('api')
			->run()
			->stopOnFail();

		$this->taskCodecept()
			->arg('--steps')
			->arg('--debug')
			->arg('--tap')
			->arg('--fail-fast')
			->arg('tests/acceptance/uninstall/')
			->run()
			->stopOnFail();

		if (!$excludePreparation)
		{
			$this->killSelenium();
		}
	}

	/**
	 * This function ensures that you have the latest version of RoboFile in your project.
	 * All redCOMPONENT RoboFiles are clones. All special needs for a project are stored in a robofile.yml file
	 *
	 * @return void
	 */
	public function checkRoboFileVersion()
	{
		$this->taskCheckRoboFileVersion($this->version)
		     ->run()
		     ->stopOnFail();
	}

	/**
	 * Downloads Selenium Standalone Server
	 *
	 * @return void
	 */
	private function getSelenium()
	{
		if (!file_exists('selenium-server-standalone.jar'))
		{
			$this->say('Downloading Selenium Server, this may take a while.');
			$this->_exec('curl'
			             . ' -sS'
			             . ' --retry 3 --retry-delay 5'
			             . ' http://selenium-release.storage.googleapis.com/2.46/selenium-server-standalone-2.46.0.jar'
			             . ' > selenium-server-standalone.jar');
		}
	}

	/**
	 * Stops Selenium Standalone Server
	 *
	 * @return void
	 */
	private function killSelenium()
	{
		$this->_exec('curl http://localhost:4444/selenium-server/driver/?cmd=shutDownSeleniumServer');
	}

	/**
	 * Downloads Composer
	 *
	 * @return void
	 */
	private function getComposer()
	{
		// Make sure we have Composer
		if (!file_exists('./composer.phar'))
		{
			$this->_exec('curl --retry 3 --retry-delay 5 -sS https://getcomposer.org/installer | php');
		}
	}

	/**
	 * Runs Selenium Standalone Server
	 *
	 * @param   string  $path  Optional path to selenium standalone server
	 *
	 * @return void
	 */
	private function runSelenium($path = null)
	{
		if (!$path)
		{
			$path = 'selenium-server-standalone.jar';
		}

		// Running Selenium server
		$this->_exec("java -jar $path >> selenium.log 2>&1 &");
	}

    /**
     * @return Result
     */
	public function sendCodeceptionOutputToSlack($slackChannel, $slackToken, $codeceptionOutputFolder = '', $dockerAppName = '', $dockerPHPVersion = '', $dockerJoomlaVersion = '', $dockerAppVersion = '')
    {
		if ($codeceptionOutputFolder == '')
		{
		    $codeceptionOutputFolder = 'tests/_output';
		}

		if (!$slackToken)
		{
		    $this->say('Slack security token was not received');

		    return 1;
		}

		$dockerInfo = '';

        if ($dockerAppName != '')
		{
			$dockerInfo .= 'for ' . $dockerAppName;

			if ($dockerPHPVersion != '')
			{
				$dockerInfo .= ' - PHP v.' . $dockerPHPVersion;
			}

			if ($dockerJoomlaVersion != '')
			{
				$dockerInfo .= ' - Joomla v.' . $dockerJoomlaVersion;
			}

			if ($dockerAppVersion != '')
			{
				$dockerInfo .= ' - ' . $dockerAppName . ' v.' . $dockerAppVersion;
			}
        }

        $this->say('Check if there is Codeception snapshots and sending them to Slack.');
        $this->say('Looking for snapshots at:' . $codeceptionOutputFolder);

        if (!file_exists($codeceptionOutputFolder) || !(new \FilesystemIterator($codeceptionOutputFolder))->valid())
        {
            $this->say('There were no errors found by Codeception');

            return 0;
        }

        $error = false;
        $errorSnapshot = '';
        $errorsSent = false;

        // Loop throught Codeception snapshots
        if ($handler = opendir($codeceptionOutputFolder))
        {

            while (false !== ($errorSnapshot = readdir($handler)))
            {
                // Sends only png/jpg images
                if ('jpg' != substr($errorSnapshot, -3)
                	&& 'png' != substr($errorSnapshot, -3))
                {
                    continue;
                }

				$title = ($dockerAppName != '' ? $dockerAppName : 'AutomatedTest') . '_Error';
                $initial_comment = 'Error found while running automated test' . ($dockerInfo != '' ? ' ' . $dockerInfo : '');

				if (is_file('tests/_output/report.tap.log'))
				{
					$initial_comment .= chr(10) . chr(10) . str_replace('"', '', file_get_contents($codeceptionOutputFolder . '/report.tap.log', null, null, 15));
				}

                // Sends error snapshot to Slack channel
                $command = 'curl -F file=@' . $codeceptionOutputFolder . '/' . $errorSnapshot
                	. ' -F channels='. $slackChannel
                	. ' -F title=' . $title
                	. ' -F initial_comment="' . $initial_comment . '"'
                    . ' -F token=' . $slackToken
                    . ' https://slack.com/api/files.upload';
                
                $this->say($command);
                $response = json_decode(shell_exec($command));

                $result = '';

                if($response->ok) {
                    $error = false;
                }
                else {
                    $error = true;
                }

                $errorsSent = true;
            }
        }

        closedir($handler);

        if ($error) 
        {
            $this->say('Slack could not be reached');

            return 1;
        }
        else
        {
            $this->say('Error images have been sent to Slack');

            return 0;
        }
        return $result;
    }

	/**
	 * Sends error screenshots to Github
	 *
	 * @return void
	 */
	public function sendScreenshotFromTravisToGithub($cloudName, $apiKey, $apiSecret, $GithubToken, $repoOwner, $repo, $pull)
	{
		$error = false;

		// Loop throught Codeception snapshots
		if ($handler = opendir('tests/_output'))
		{
			$body = '';

			if (is_file('tests/_output/report.tap.log'))
			{
				$body = file_get_contents('tests/_output/report.tap.log', null, null, 15);
			}

			while (false !== ($errorSnapshot = readdir($handler)))
			{
				// Avoid sending system files or html files
				if (!('png' === pathinfo($errorSnapshot, PATHINFO_EXTENSION)))
				{
					continue;
				}

				$error = true;
				$this->say("Uploading screenshots: $errorSnapshot");

				Cloudinary::config(
					array(
						'cloud_name' => $cloudName,
						'api_key'    => $apiKey,
						'api_secret' => $apiSecret
					)
				);

				$result = \Cloudinary\Uploader::upload(realpath(dirname(__FILE__) . '/tests/_output/' . $errorSnapshot));
				$this->say($errorSnapshot . 'Image sent');
				$body .= '![Screenshot](' . $result['secure_url'] . ')';
			}

			if ($error)
			{
				$this->say('Creating Github issue');
				$client = new \Github\Client;
				$client->authenticate($GithubToken, \Github\Client::AUTH_HTTP_TOKEN);
				$client
					->api('issue')
					->comments()->create(
						$repoOwner, $repo, $pull,
						array(
							'body'  => $body
						)
					);
			}
		}
	}

	/**
	 * Function to Run all Docker Envorionment Testing
	 *
	 * @return void
	 */
	public function runDockerTestEnvironment()
	{
		$dockerConfig = file_exists('tests/docker/dockertests.yml') ? Yaml::parse(file_get_contents('tests/docker/dockertests.yml')) : [];

		// Checks if config exists and if there are php versions and Joomla versions to test
		if ($dockerConfig && count($dockerConfig)
			&& isset($dockerConfig['appname']) && $dockerConfig['appname'] != ''
			&& isset($dockerConfig['phpversions']) && count($dockerConfig['phpversions'])
			&& isset($dockerConfig['joomlaversions']) && count($dockerConfig['joomlaversions'])
			)
		{
			$baseDir = getcwd();

			// Array for storing containers
			$dockerContainer = array();

			// Base app name in lowercase to prevent problems in docker image and container names
			$appName = strtolower($dockerConfig['appname']);

			// Docker variables to replace in all files using variables
			$dockerVariables = array(
				'app:name',
				'php:version',
				'joomla:version',
				'github:token',
				'slack:token',
				'slack:channel'
			);

			// Initial Docker values to replace for variables (it will be populated as needed in the upcoming loops)
			$dockerValues = array(
				'app:name' => $appName
			);

			$dockerValues['github:token'] = isset($dockerConfig['github-token']) ? $dockerConfig['github-token'] : (getenv('GITHUB_TOKEN') ? getenv('GITHUB_TOKEN') : '');
			$dockerValues['slack:token'] = isset($dockerConfig['slack-token']) ? $dockerConfig['slack-token'] : (getenv('SLACK_TOKEN') ? getenv('SLACK_TOKEN') : '');
			$dockerValues['slack:channel'] = isset($dockerConfig['slack-channel']) ? $dockerConfig['slack-channel'] : (getenv('SLACK_CHANNEL') ? getenv('SLACK_CHANNEL') : '');

			// Tries to delete the _dockerfiles working folder if it exists, and re-creates it
			try
			{
				$this->_deleteDir('tests/_dockerfiles');
			}
			catch (Exception $e)
			{
			}

			$this->taskFileSystemStack()
				->mkdir('tests/_dockerfiles')
				->run();

			// Clones Joomla under _dockerfiles/cms for each version to test according to yml file
			foreach ($dockerConfig['joomlaversions'] as $joomlaVersion)
			{
				$this->taskGitStack()
					->cloneRepo('-b ' . $joomlaVersion . ' --single-branch --depth 1 https://github.com/joomla/joomla-cms.git', 'tests/_dockerfiles/cms/' . $joomlaVersion)
					->run();
			}

			// Zips Joomla
			chdir('tests/_dockerfiles/cms');
			$this->_exec('zip -q -r .cms.zip . -x *.git/*');
			chdir($baseDir);
			$this->taskFileSystemStack()
				->rename('tests/_dockerfiles/cms/.cms.zip', 'tests/_dockerfiles/.cms.zip')
				->run();

			// Zips App
			$this->_exec('zip -q -r tests/_dockerfiles/.' . $appName . '.zip . -x .git/**\* .dist/**\* .releases/**\* .travis/**\* node_modules/**\* releases/**\* tests/**\* vendor/**\*');

			// Zips Test scripts
			$this->_exec('zip --symlinks -q -r tests/_dockerfiles/.tests.zip codeception.* composer.* RoboFile.php tests vendor -x tests/_dockerfiles/**\*');

			// DB container run (trying to stop it first in case it already exists)
			try
			{
				$this->taskDockerStop('db')->run();
				$this->taskDockerRemove('db')->run();
			}
			catch (Exception $e)
			{
			}

			$dockerContainer['db'] = $this->taskDockerRun('mysql')
				->detached()
				->env('MYSQL_ROOT_PASSWORD', 'root')
				->name('db')
				->publish(13306, 3306)
				->run();

			// Pulls the latest version of the joomla-test-client image
			$this->taskDockerPull('jatitoam/joomla-test-client:firefox')
				->run();

			$i = 0;

			// Actions per php version to test
			foreach ($dockerConfig['phpversions'] as $phpVersion)
			{
				$dockerValues['php:version'] = $phpVersion;

				// Creates the base php folder to build the server (app-layer) container.  Also copies base Dockerfile replacing variables
				$this->taskFileSystemStack()
					->mkdir('tests/_dockerfiles/php/' . $phpVersion)
					->copy('tests/docker/Dockerfile-server', 'tests/_dockerfiles/php/' . $phpVersion . '/Dockerfile')
					->run();
				$this->replaceVariablesInFile('tests/_dockerfiles/php/' . $phpVersion . '/Dockerfile', $dockerVariables, $dockerValues);

				// Unzips Joomla installs to make them available to the containers
				$this->_exec('unzip -q tests/_dockerfiles/.cms.zip -d tests/_dockerfiles/php/' . $phpVersion . '/joomla');

				// Unzips app installer to make it available to the containers (it does not include tests and other folders that won't be needed in release)
				$this->_exec('unzip -q tests/_dockerfiles/.' . $appName . '.zip -d tests/_dockerfiles/php/' . $phpVersion . '/' . $appName);

				// Tries to stop the container in case one with the same name already exists
				try
				{
					$this->taskDockerStop($appName . '-test-server-' . $phpVersion)->run();
					$this->taskDockerRemove($appName . '-test-server-' . $phpVersion)->run();
				}
				catch (Exception $e)
				{
				}

				// Tries to delete the image to be created, in case it already exists
				try
				{
					$this->_exec('docker rmi ' . $appName . '-test-server:' . $phpVersion);
				}
				catch (Exception $e)
				{
				}

				// Pulls the latest version of the joomla-test-server image for this php version
				$this->taskDockerPull('jatitoam/joomla-test-server:' . $phpVersion)
					->run();

				// Builds the specific php-version container of the joomla-test-server Docker image
				$this->taskDockerBuild('tests/_dockerfiles/php/' . $phpVersion)
					->tag($appName . '-test-server:' . $phpVersion)
					->run();

				// Executes the app-layer container for this php version
				// @toDO: Allow multiple versions of the app, for now it's "unique" version
				$dockerContainer['php-' . $phpVersion] = $this->taskDockerRun($appName . '-test-server:' . $phpVersion)
					->detached()
					->env('JOOMLA_TEST_APP_NAME', $appName)
					->env('JOOMLA_TEST_JOOMLA_VERSIONS', implode(',', $dockerConfig['joomlaversions']))
					->env('JOOMLA_TEST_APP_VERSIONS', 'unique')
					->name($appName . '-test-server-' . $phpVersion)
					->publish(8000 + (int) str_replace('.', '', $phpVersion), 80)
					->link($dockerContainer['db'], 'mysql')
					->run();

				// Client-actions related to the specific Joomla version
				foreach ($dockerConfig['joomlaversions'] as $joomlaVersion)
				{
					$dockerValues['joomla:version'] = $joomlaVersion;

					// PHP-Joomla version client container folder with app installer and container configuration files
					$this->taskFileSystemStack()
						->mkdir('tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName)
						->copy('tests/docker/Dockerfile-client', 'tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/Dockerfile')
						->copy('tests/docker/docker-entrypoint-specific.sh', 'tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/entrypoint-specific.sh')
						->run();
					$this->replaceVariablesInFile('tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/Dockerfile', $dockerVariables, $dockerValues);
					$this->replaceVariablesInFile('tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/entrypoint-specific.sh', $dockerVariables, $dockerValues);

					// Unzips app tests files/folders in the container files
					$this->_exec('unzip -q tests/_dockerfiles/.tests.zip -d tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName);

					// Codeception configuration files with variable replacement
					$this->taskFileSystemStack()
						->copy('tests/docker/acceptance.suite.dist.yml', 'tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/acceptance.suite.yml')
						->copy('tests/docker/api.suite.dist.yml', 'tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/api.suite.yml')
						->run();
					$this->replaceVariablesInFile('tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/api.suite.yml', $dockerVariables, $dockerValues);
					$this->replaceVariablesInFile('tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion . '/' . $appName . '/tests/acceptance.suite.yml', $dockerVariables, $dockerValues);

					// Tries to stop the container in case one with the same name already exists
					try
					{
						$this->taskDockerStop($appName . '-test-client-' . $phpVersion . '-' . $joomlaVersion)->run();
						$this->taskDockerRemove($appName . '-test-client-' . $phpVersion . '-' . $joomlaVersion)->run();
					}
					catch (Exception $e)
					{
					}

					// Tries to delete the image to be created, in case it already exists
					try
					{
						$this->_exec('docker rmi ' . $appName . '-test-client:' . $phpVersion . '-' . $joomlaVersion);
					}
					catch (Exception $e)
					{
					}

					// Builds the specific php/joomla-version container of the joomla-test-client Docker image
					$this->taskDockerBuild('tests/_dockerfiles/client/' . $phpVersion . '/' . $joomlaVersion)
						->tag($appName . '-test-client:' . $phpVersion . '-' . $joomlaVersion)
						->run();

					// Executes the client-layer container for this php/joomla version
					// @toDO: Allow multiple versions of the app, for now it's "unique" version
					$dockerContainer['client-' . $phpVersion . '-' . $joomlaVersion] = $this->taskDockerRun($appName . '-test-client:' . $phpVersion . '-' . $joomlaVersion)
						->name($appName . '-test-client-' . $phpVersion . '-' . $joomlaVersion)
						->publish(5900 + $i, 5900)
						->link($dockerContainer['php-' . $phpVersion], $appName . '-test-server-' . $phpVersion)
						->run();

					$i ++;
				}
			}
		}
	}

	/**
	 * Function to replace Docker variables in a given file
	 *
	 * @param   string  $fileName   Name of the file (path)
	 * @param   array   $variables  Variables to replace in file
	 * @param   array   $values     Values to replace
	 *
	 * @return void
	 */
	protected function replaceVariablesInFile($fileName, $variables, $values)
	{
		foreach ($variables as $variable)
		{
			if (isset($values[$variable]))
			{
				$this->taskReplaceInFile($fileName)
					->from('{' . $variable . '}')
					->to($values[$variable])
					->run();
			}
		}
	}

	/**
	 * Function to Run tests from Docker Container
	 *
	 * @return void
	 */
	public function runTestsFromDockerContainer()
	{
		$this->runTests(true);
	}
}