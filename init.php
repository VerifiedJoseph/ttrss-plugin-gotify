<?php

require_once __DIR__ . "/include/Request.php";

class gotify_notifications extends Plugin {

	/* @var PluginHost $host */
	private $host;

	private string $useragent = 'Tiny Tiny RSS Gotify plugin (https://github.com/VerifiedJoseph/ttrss-plugin-gotify)';

	private string $server;
	private string $token;
	private string $priority;

	private array $enabled_feeds;
	private array $app_tokens;

	function about() {
		return array(
			'1.3',
			'Send push notifications with Gotify on new feed items',
			'VerifiedJoseph');
	}

	function api_version()
	{
		return 2;
	}

	function init($host)
	{
		$this->host = $host;
		$host->add_hook($host::HOOK_FILTER_TRIGGERED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
		$host->add_filter_action($this, "Gotify", 'Send Notification');

		$this->server = $this->host->get($this, 'server');
		$this->token = $this->host->get($this, 'app_token');
		$this->priority = $this->host->get($this, 'priority');

		$this->enabled_feeds = $this->get_stored_array('enabled_feeds');
		$this->app_tokens = $this->get_stored_array('app_tokens');
	}

	function save()
	{
		$this->host->set($this, 'server', $_POST['server']);
		$this->host->set($this, 'app_token', $_POST['app_token']);
		$this->host->set($this, 'priority', $_POST['priority']);

		echo __('Data saved.');
	}

    public function test_notification()
	{
        $server = $_POST['server'];
        $token = $_POST['app_token'];
        $priority = (int) $_POST['priority'];

		try {
			$this->sendMessage(
				'Test Notification',
				'Test notification from Tiny Tiny RSS',
				null,
				$server,
				$token,
				$priority
			);

			echo __("Test notification sent");
		} catch (Exception $err) {
			echo __($err->getMessage());
		}
    }

	function hook_prefs_tab($args)
	{
		if ($args != 'prefFeeds') {
			return;
		}

		$notice = format_notice('Enable for specific feeds in the feed editor.');
		$pluginHandlerTags = \Controls\pluginhandler_tags($this, 'save');

		$attributes = array('required' => 1, 'dojoType' => 'dijit.form.ValidationTextBox');

		$serverInputTag =  \Controls\input_tag(
			'server', htmlspecialchars($this->server), 'text', $attributes
		);

		$appTokenInputTag = \Controls\input_tag(
			'app_token', htmlspecialchars($this->token), 'text', $attributes
		);

		$priorityInputTag = \Controls\number_spinner_tag('priority', $this->priority, ['required' => 1]);
		$submitTag = \Controls\submit_tag(__('Save'));

		$this->enabled_feeds = $this->filter_unknown_feeds($this->enabled_feeds);

		$this->host->set($this, 'enabled_feeds', $this->enabled_feeds);

		$feedList = '';
		if (count($this->enabled_feeds) > 0) {
			$feedList = $this->getFeedList($this->enabled_feeds);
		}

		print <<<HTML
			<div dojoType="dijit.layout.AccordionPane" title="<i class='material-icons'>extension</i> Gotify settings">
				{$notice}
				<form id="gotify" dojoType="dijit.form.Form">
					{$pluginHandlerTags}
					<script type="dojo/method" event="onSubmit" args="evt">
						evt.preventDefault();
						if (this.validate()) {
							Notify.progress("Saving data...", true);
							xhr.post("backend.php", this.getValues(), (reply) => {
								Notify.info(reply);
							})
						}
					</script>
					<section>
						<fieldset>
							<label>Server:</label>
							{$serverInputTag}
						</fieldset>
						<fieldset>
							<label>App Token:</label>
							{$appTokenInputTag}
						</fieldset>
						<fieldset>
						<label>Message Priority:</label>
							{$priorityInputTag}
						</fieldset>
					</section>
					<hr/>
					{$submitTag}
					<button dojoType="dijit.form.Button">
						Test
						<script type="dojo/on" data-dojo-event="click" data-dojo-args="evt">
							require(['dojo/dom-form'], function(domForm) {
								Notify.progress('Sending test notification...', true);

								var gotifyData = domForm.toObject('gotify')
								gotifyData.method = "test_notification"

								xhr.post("backend.php", gotifyData, reply => {
									if (reply.errors) {
										Notify.error(reply.errors.join('; '));
									} else {
										Notify.info(reply);
									}
								});
							});
						</script>
					</button>
				</form>
				{$feedList}
			</div>
		HTML;
	}

	function hook_prefs_edit_feed($feed_id)
	{
		$token = '';
		if (array_key_exists($feed_id, $this->app_tokens) === true) {
			$token = $this->app_tokens[$feed_id];
		}

		$checkboxTag = \Controls\checkbox_tag('gotify_enabled', in_array($feed_id, $this->enabled_feeds));
		$tokenInputTag =  \Controls\input_tag(
			'gotify_token', htmlspecialchars($token), 'text', array('dojoType' => 'dijit.form.ValidationTextBox')
		);

		print <<<HTML
			<header>Gotify Notifications</header>
			<section>
				<fieldset>
				<label>Enable:</label>
					{$checkboxTag}
				</fieldset>
				<fieldset>
				<label>App token:</label>
					{$tokenInputTag} <span title="Set app token specifically for this feed.">[?]</span>
				</fieldset>
			</section>
		HTML;
	}

	function hook_prefs_save_feed($feed_id)
	{
		$enable_key = array_search($feed_id, $this->enabled_feeds);

		$enable = checkbox_to_sql_bool($_POST['gotify_enabled'] ?? '');
		$token = $_POST['gotify_token'] ?? '';

		if ($enable) {
			if ($enable_key === false) {
				array_push($this->enabled_feeds, $feed_id);
			}
		} else {
			if ($enable_key !== false) {
				unset($this->enabled_feeds[$enable_key]);
			}
		}

		$this->host->set($this, 'enabled_feeds', $this->enabled_feeds);

		if ($token !== '') {
			$this->app_tokens[$feed_id] = $token;
		} else if ($token === '' && array_key_exists($feed_id, $this->app_tokens)) {
			unset($this->app_tokens[$feed_id]);
		}

		$this->host->set($this, 'app_tokens', $this->app_tokens);
	}

	private function get_stored_array($name)
	{
		$tmp = $this->host->get($this, $name);

		if (!is_array($tmp)) $tmp = [];

		return $tmp;
	}

    public function hook_article_filter_action($article, $action) {
		$feed_id = $article['feed']['id'];

		$token = $this->token;
		if (array_key_exists($feed_id, $this->app_tokens) === true) {
			Debug::log('[Gotify] Using feed specific app token');
			$token = $this->app_tokens[$feed_id];
		}

		$feed_id = $article['feed']['id'];

		try {
			$this->sendMessage(
				Feeds::_get_title($feed_id),
				$article['title'],
				$article['link'],
				$this->server,
				$token,
				$this->priority
			);
		} catch (Exception $err) {
			Debug::log('[Gotify] ' . $err->getMessage());
		}
    }

	function hook_filter_triggered($feed_id, $owner_uid, $article, $matched_filters, $matched_rules, $article_filters)
	{
		$feed_id = $article['feed']['id'];

		$token = $this->token;
		if (array_key_exists($feed_id, $this->app_tokens) === true) {
			Debug::log('[Gotify] Using feed specific app token');
			$token = $this->app_tokens[$feed_id];
		}

		try {
			if (in_array($feed_id, $this->enabled_feeds) === false) {
				throw new Exception('Gotify not enabled for this feed.');
			}

			if ($this->has_article_filter_action($article_filters, 'filter') === true) {
				throw new Exception('Article deleted via filter. Not sending message.');
			}

			if ($this->has_article_filter_action($article_filters, 'catchup') === true) {
				throw new Exception('Article marked as read via filter. Not sending message.');
			}

			if ($this->isNewArticle($article['guid_hashed']) === false) {
				throw new Exception('Article is not new. Not sending message');
			}

			$this->sendMessage(
				Feeds::_get_title($feed_id),
				$article['title'],
				$article['link'],
				$this->server,
				$token,
				$this->priority
			);
		} catch (Exception $err) {
			Debug::log('[Gotify] ' . $err->getMessage());
		}
	}

	// Copy of RSSUtils::has_article_filter_action()
	private function has_article_filter_action(array $filter_actions, string $filter_action_type)
	{
		foreach ($filter_actions as $fa) {
			if ($fa["type"] == $filter_action_type) {
				return true;
			};
		}

		return false;
	}

	private function filter_unknown_feeds($enabled_feeds)
	{
		$tmp = array();

		foreach ($enabled_feeds as $feed) {
			$sth = $this->pdo->prepare('SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?');
			$sth->execute([$feed, $_SESSION['uid']]);

			if ($row = $sth->fetch()) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
	}

	private function sendMessage($title, $body, $url, $server, $token, $priority)
	{
		Debug::log('[Gotify] Sending message via ' . $server, Debug::LOG_VERBOSE);

		if ($server === false) {
			throw new Exception('No Gotify server URL set.');
		}

		if ($token === false) {
			throw new Exception('No Gotify app token set.');
		}

		$server = $this->validateServerUrl($server);

		$headers = [
			'content-type' => 'application/json; charset=utf-8',
			'x-gotify-key' => $token
		];

		$payload = [
			'title' => $title,
			'message' => $body,
			'priority' => (int) $priority
		];

		if ($url !== null) {
			$payload['extras'] = [
				'client::notification' => [
					'click' => ['url' => $url]
				]
			];
		}

		$request = new Request($this->useragent);
		$response = $request->post(
			$server . 'message',
			$payload,
			$headers
		);

		if ($response['statusCode'] !== 200) {
			$message = sprintf(
				'Sending message failed. Status code: %s Body: %s',
				$response['statusCode'],
				$response['body'
			]);

			Logger::log(E_USER_ERROR, 'Gotify error: ' . $message);
			throw new Exception($message);
		}

		Logger::log(E_USER_NOTICE, sprintf(
			"Gotify: Sent message for %s",
			$body
		));
	}

	private function isNewArticle($guid)
	{
		$sth = $this->pdo->prepare('SELECT id FROM ttrss_entries WHERE guid = ?');
		$sth->execute([$guid]);

		if (!$row = $sth->fetch()) {
			return true;
		}

		return false;
	}

	private function getFeedList($feeds)
	{
		$list ='';

		foreach ($feeds as $f) {
			$title = Feeds::_get_title($f);

			$list .= <<<HTML
				<li>
					<i class="material-icons">rss_feed</i>
					<a href="#"	onclick="CommonDialogs.editFeed({$f})">
						{$title}
					</a>
				</li>
			HTML;
		}

		return <<<HTML
			<hr/><h3>Currently enabled for</h3>
			<ul class="panel panel-scrollable list list-unstyled">
				{$list}
			</ul>
		HTML;
	}

	private function validateServerUrl(string $server): string
	{
		if (preg_match('/^https?:\/\//', $server) === 0) {
			$message = 'Gotify server must start with https:// or http://';
			Logger::log(E_USER_ERROR, 'Gotify error: ' . $message);

			throw new Exception($message);
		}

		if (substr($server, -1) !== '/') {
			$server .= '/';
		}

		return $server;
	}
}
