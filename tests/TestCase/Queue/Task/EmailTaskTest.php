<?php
declare(strict_types=1);

namespace Queue\Test\TestCase\Queue\Task;

use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Mailer\Mailer;
use Cake\Mailer\Transport\DebugTransport;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;
use Queue\Console\Io;
use Queue\Queue\Task\EmailTask;
use Shim\TestSuite\ConsoleOutput;
use Shim\TestSuite\TestTrait;
use Tools\Mailer\Message as MailerMessage;

class EmailTaskTest extends TestCase {

	use TestTrait;

	/**
	 * @var array
	 */
	protected array $fixtures = [
		'plugin.Queue.QueuedJobs',
	];

	/**
	 * @var \Queue\Queue\Task\EmailTask|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $Task;

	/**
	 * @var \Shim\TestSuite\ConsoleOutput
	 */
	protected $out;

	/**
	 * @var \Shim\TestSuite\ConsoleOutput
	 */
	protected $err;

	/**
	 * Setup Defaults
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->out = new ConsoleOutput();
		$this->err = new ConsoleOutput();
		$io = new Io(new ConsoleIo($this->out, $this->err));

		$this->Task = new EmailTask($io);
	}

	/**
	 * @return void
	 */
	public function testAdd() {
		Configure::write('Config.adminEmail', 'test@test.de');
		$this->Task->add(null);

		Configure::delete('Config.adminEmail');

		/** @var \Queue\Model\Table\QueuedJobsTable $queuedJobsTable */
		$queuedJobsTable = $this->getTableLocator()->get('Queue.QueuedJobs');

		/** @var \Queue\Model\Entity\QueuedJob $queuedJob */
		$queuedJob = $queuedJobsTable->find()->orderDesc('id')->firstOrFail();
		$this->assertSame('Queue.Email', $queuedJob->job_task);
	}

	/**
	 * @return void
	 */
	public function testRunArray() {
		$settings = [
			'from' => 'test@test.de',
			'to' => 'test@test.de',
		];

		$data = [
			'settings' => $settings,
			'content' => 'Foo Bar',
		];
		$this->Task->run($data, 0);

		$this->assertInstanceOf(Mailer::class, $this->Task->mailer);

		$debugEmail = $this->Task->mailer;

		$transportConfig = $debugEmail->getTransport()->getConfig();
		$this->assertSame('Debug', $transportConfig['className']);
	}

	/**
	 * @return void
	 */
	public function testRunArrayEmailComplex() {
		$settings = [
			'from' => ['test@test.de', 'My Name'],
			'to' => ['test@test.de', 'Your Name'],
			'cc' => [
				[
					'copy@test.de' => 'Your Name',
					'copy-other@test.de' => 'Your Other Name',
				],
			],
			'helpers' => [['Shim.Configure']],
		];

		$data = [
			'settings' => $settings,
			'content' => 'Foo Bar',
		];
		$this->Task->run($data, 0);

		$this->assertInstanceOf(Mailer::class, $this->Task->mailer);

		$debugEmail = $this->Task->mailer;

		$transportConfig = $debugEmail->getTransport()->getConfig();
		$this->assertSame('Debug', $transportConfig['className']);

		$this->assertSame(['test@test.de' => 'Your Name'], $debugEmail->getTo());
		$this->assertSame($settings['cc'][0], $debugEmail->getCc());
	}

	/**
	 * @return void
	 */
	public function testRunToolsEmailMessageClassString() {
		$class = MailerMessage::class;
		$settings = [
			'from' => 'test@test.de',
			'test@test.de',
			'text' => 'Foo Bar',
		];

		/** @var \Queue\Model\Table\QueuedJobsTable $queuedJobsTable */
		$queuedJobsTable = $this->getTableLocator()->get('Queue.QueuedJobs');
		$queuedJobsTable->createJob('Queue.Email', ['class' => $class, 'settings' => $settings]);

		$queuedJob = $queuedJobsTable->find()->orderDesc('id')->firstOrFail();
		$data = unserialize($queuedJob->data);
		/** @var \TestApp\Mailer\TestMailer $mailer */
		$class = $data['class'];

		$transportMock = $this->createMock(
			DebugTransport::class,
		);
		$transportMock
			->expects($this->once())
			->method('send')
			->with($this->equalTo(new $class($settings)))
			->willReturn(['headers' => [], 'message' => '']);
		TransportFactory::getRegistry()->set('test_mock', $transportMock);

		$data = [
			'transport' => 'test_mock',
			'class' => $class,
			'settings' => $settings,
		];

		$this->Task->run($data, 0);
	}

	/**
	 * Helper method for skipping tests that need a non Postgres connection.
	 *
	 * @return void
	 */
	protected function _skipPostgres() {
		$config = ConnectionManager::getConfig('test');
		$skip = strpos($config['driver'], 'Postgres') !== false;
		$this->skipIf($skip, 'Only non Postgres is working yet for this.');
	}

}
