<?php
require_once __DIR__ . "/vendor/autoload.php";

use Gotify\Exception\GotifyException;
use Gotify\Exception\EndpointException;

class gotify_notifications extends Plugin {

	/* @var PluginHost $host */
	private $host;

	function about() {
		return array(null,
			'Send push notifications with Gotify on new feed items',
			'VerifiedJoseph');
	}

	function api_version()
	{
		return 2;
	}

	function save()
	{
		$this->host->set($this, 'server', $_POST['server']);
		$this->host->set($this, 'app_token', $_POST['app_token']);
		$this->host->set($this, 'priority', $_POST['priority']);

		echo __('Data saved.');
	}

	function init($host)
	{
		$this->host = $host;
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
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
				<form dojoType="dijit.form.Form">
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
				</form>
				{$feedList}
			</div>
		HTML;
	}

	function hook_prefs_edit_feed($feed_id)
	{
		$enabled_feeds = $this->get_stored_array('enabled_feeds');
		$checkboxTag = \Controls\checkbox_tag('gotify_enabled', in_array($feed_id, $enabled_feeds));

		print <<<HTML
			<header>Gotify</header>
			<section>
				<fieldset>
					<label class="checkbox">
						{$checkboxTag}
						Enable
					</label>
				</fieldset>
			</section>
		HTML;
	}

	function hook_prefs_save_feed($feed_id)
	{
		$enabled_feeds = $this->get_stored_array('enabled_feeds');
		$enable_key = array_search($feed_id, $enabled_feeds);

		$enable = checkbox_to_sql_bool($_POST['gotify_enabled'] ?? '');

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
	}

	private function get_stored_array($name)
	{
		$tmp = $this->host->get($this, $name);

		if (!is_array($tmp)) $tmp = [];

		return $tmp;
	}

	function hook_article_filter($article)
	{
		$enabled_feeds = $this->get_stored_array('enabled_feeds');
		$feed_id = $article['feed']['id'];

		if (in_array($feed_id, $enabled_feeds)) {
			if ($this->isNewArticle($article['guid_hashed']) === true) {
				$this->sendMessage(
					Feeds::_get_title($feed_id),
					$article['title'],
					$article['link']
				);
			} else {
				Debug::log('[Gotify] Article is not new. Not sending message.', Debug::LOG_VERBOSE);
			}
		}

		return $article;
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

	private function sendMessage($feedName, $title, $url)
	{
		$serverUri = $this->host->get($this, 'server');
		$token = $this->host->get($this, 'app_token');
		$priority = (int) $this->host->get($this, 'priority');

		Debug::log('[Gotify] Sending message via ' . $serverUri, Debug::LOG_VERBOSE);

		try {
			$server = new Gotify\Server($serverUri);
			$auth = new Gotify\Auth\Token($token);
			$message = new Gotify\Endpoint\Message($server, $auth);
		
			$messageTitle = '[tt-rss] ' . $feedName;
			$messageBody = $title;
			$messageExtras = array(
				'client::notification' => array(
					'click' => array('url' => $url)
				)
			);

			$message->create(
				$messageTitle,
				$messageBody,
				$priority,
				$messageExtras
			);

			Logger::log(E_USER_NOTICE, 'Gotify: Sent message. ['. $feedName .'] ' . $messageBody);
		} catch (EndpointException | GotifyException $err) {
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
}
