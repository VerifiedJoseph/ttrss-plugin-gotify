<?php

require_once __DIR__ . "/include/Request.php";

class gotify_notifications extends Plugin {

	/* @var PluginHost $host */
	private $host;

	private string $useragent = 'Tiny Tiny RSS Gotify plugin (https://github.com/VerifiedJoseph/ttrss-plugin-gotify)';

	function about() {
		return array(
			'1.2',
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
        $priority = (int) $_POST['priority'] ?? 4;

		try {
			if ($server === false) {
				throw new Exception('No Gotify server URL set.');
			}

			if ($token === false) {
				throw new Exception('No Gotify app token set.');
			}

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

		$server = $this->host->get($this, 'server');
		$token = $this->host->get($this, 'app_token');
		$priority = $this->host->get($this, 'priority');

		if ($priority === false) {
			$priority = 0;
		}

		$notice = format_notice('Enable for specific feeds in the feed editor.');
		$pluginHandlerTags = \Controls\pluginhandler_tags($this, 'save');

		$attributes = array('required' => 1, 'dojoType' => 'dijit.form.ValidationTextBox');

		$serverInputTag =  \Controls\input_tag(
			'server', htmlspecialchars($server), 'text', $attributes
		);

		$appTokenInputTag = \Controls\input_tag(
			'app_token', htmlspecialchars($token), 'text', $attributes
		);

		$priorityInputTag = \Controls\number_spinner_tag('priority', $priority, ['required' => 1]);
		$submitTag = \Controls\submit_tag(__('Save'));

		$enabledFeeds = $this->filter_unknown_feeds(
			$this->get_stored_array('enabled_feeds')
		);

		$this->host->set($this, 'enabled_feeds', $enabledFeeds);

		$feedList = '';
		if (count($enabledFeeds) > 0) {
			$feedList = $this->getFeedList($enabledFeeds);
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
						<fieldset class="prefs">
							<label>Server:</label>
							{$serverInputTag}
						</fieldset>
						<fieldset class="prefs">
							<label>App Token:</label>
							{$appTokenInputTag}
						</fieldset>
						<fieldset class="prefs">
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
		$enabled_feeds = $this->get_stored_array('enabled_feeds');
		$app_tokens = $this->get_stored_array('app_tokens');

		$token = '';
		if (array_key_exists($feed_id, $app_tokens) === true) {
			$token = $app_tokens[$feed_id];
		}

		$checkboxTag = \Controls\checkbox_tag('gotify_enabled', in_array($feed_id, $enabled_feeds));
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
		$enabled_feeds = $this->get_stored_array('enabled_feeds');
		$app_tokens = $this->get_stored_array('app_tokens');

		$enable_key = array_search($feed_id, $enabled_feeds);

		$enable = checkbox_to_sql_bool($_POST['gotify_enabled'] ?? '');
		$token = $_POST['gotify_token'] ?? '';

		if ($enable) {
			if ($enable_key === false) {
				array_push($enabled_feeds, $feed_id);
			}
		} else {
			if ($enable_key !== false) {
				unset($enabled_feeds[$enable_key]);
			}
		}

		$this->host->set($this, 'enabled_feeds', $enabled_feeds);

		if ($token !== '') {
			$app_tokens[$feed_id] = $token;
		} else if ($token === '' && array_key_exists($feed_id, $app_tokens)) {
			unset($app_tokens[$feed_id]);
		}

		$this->host->set($this, 'app_tokens', $app_tokens);
	}

	private function get_stored_array($name)
	{
		$tmp = $this->host->get($this, $name);

		if (!is_array($tmp)) $tmp = [];

		return $tmp;
	}

    public function hook_article_filter_action($article, $action) {
		$app_tokens = $this->get_stored_array('app_tokens');
		$feed_id = $article['feed']['id'];

		$server = $this->host->get($this, 'server');
		$token = $this->host->get($this, 'app_token');
		$priority = (int) $this->host->get($this, 'priority');

		if (array_key_exists($feed_id, $app_tokens) === true) {
			Debug::log('[Gotify] Using feed specific app token');
			$token = $app_tokens[$feed_id];
		}

		$feed_id = $article['feed']['id'];

		try {
			if ($server === false) {
				throw new Exception('No Gotify server URL set.');
			}

			if ($token === false) {
				throw new Exception('No Gotify app token set.');
			}

			$this->sendMessage(
				Feeds::_get_title($feed_id),
				$article['title'],
				$article['link'],
				$server,
				$token,
				$priority
			);
		} catch (Exception $err) {
			Debug::log('[Gotify] ' . $err->getMessage());
		}
    }

	function hook_filter_triggered($feed_id, $owner_uid, $article, $matched_filters, $matched_rules, $article_filters)
	{
		$enabled_feeds = $this->get_stored_array('enabled_feeds');
		$app_tokens = $this->get_stored_array('app_tokens');
		$feed_id = $article['feed']['id'];

		$server = $this->host->get($this, 'server');
		$token = $this->host->get($this, 'app_token');
		$priority = (int) $this->host->get($this, 'priority');

		if (array_key_exists($feed_id, $app_tokens) === true) {
			Debug::log('[Gotify] Using feed specific app token');
			$token = $app_tokens[$feed_id];
		}

		try {
			if ($server === false) {
				throw new Exception('No Gotify server URL set.');
			}

			if ($token === false) {
				throw new Exception('No Gotify app token set.');
			}

			if (in_array($feed_id, $enabled_feeds) === false) {
				throw new Exception('Gotify not enabled for this feed.');
			}

			if (RSSUtils::find_article_filter($article_filters, 'filter') !== null) {
				throw new Exception('Article deleted via filter. Not sending message.');
			}

			if (RSSUtils::find_article_filter($article_filters, 'catchup') !== null) {
				throw new Exception('Article mark as read via filter. Not sending message.');
			}

			if ($this->isNewArticle($article['guid_hashed']) === false) {
				throw new Exception('Article is not new. Not sending message');
			}

			$this->sendMessage(
				Feeds::_get_title($feed_id),
				$article['title'],
				$article['link'],
				$server,
				$token,
				$priority
			);
		} catch (Exception $err) {
			Debug::log('[Gotify] ' . $err->getMessage());
		}
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

		try {
			$server = $this->validateServerUrl($server);

			$headers = [
				'content-type' => 'application/json; charset=utf-8',
				'x-gotify-key' => $token
			];

			$payload = [
				'title' => $title,
				'message' => $body,
				'priority' => $priority
			];

			if ($url !== null) {
				$payload['extras'][] = [
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
				throw new Exception(sprintf(
					'Sending message failed. Status code: %s Body: %s',
					$response['statusCode'],
					$response['body'
				]));
			}

			Logger::log(E_USER_NOTICE, 'Gotify: Sent message.');
		} catch (Exception $err) {
			Debug::log('[Gotify] ' . $err->getMessage());
			Logger::log(E_USER_ERROR, 'Gotify error: ' . $err->getMessage());
		}
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
			throw new Exception('Gotify server must start with https:// or http://');
		}

		if (substr($server, -1) !== '/') {
			$url .= '/';
		}

		return $server;
	}
}
