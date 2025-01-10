<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.jdocmanual
 *
 * @copyright   (C) 2013 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Cefjdemos\Plugin\Finder\Jdocmanual\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Finder as FinderEvent;
use Joomla\Component\Finder\Administrator\Indexer\Adapter;
use Joomla\Component\Finder\Administrator\Indexer\Helper;
use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Indexer\Result;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finder adapter for Joomla Jdocmanual.
 *
 * @since  3.1
 */
final class Jdocmanual extends Adapter implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * The plugin identifier.
     *
     * @var    string
     * @since  3.1
     */
    protected $context = 'Jdocmanual';

    /**
     * The extension name.
     *
     * @var    string
     * @since  3.1
     */
    protected $extension = 'com_jdocmanual';

    /**
     * The sublayout to use when rendering the results.
     *
     * @var    string
     * @since  3.1
     */
    protected $layout = 'jdocmanual';

    /**
     * The type of content that the adapter indexes.
     *
     * @var    string
     * @since  3.1
     */
    protected $type_title = 'Jdocmanual';

    /**
     * The table name.
     *
     * @var    string
     * @since  3.1
     */
    protected $table = '#__jdm_articles';

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * The field the published state is stored in.
     *
     * @var    string
     * @since  3.1
     */
    protected $state_field = 'state';

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   5.2.0
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onFinderAfterDelete' => 'onFinderAfterDelete',
            'onFinderAfterSave'   => 'onFinderAfterSave',
            'onFinderBeforeSave'  => 'onFinderBeforeSave',
            'onFinderChangeState' => 'onFinderChangeState',
        ]);
    }

    /**
     * Method to remove the link information for items that have been deleted.
     *
     * @param   FinderEvent\AfterDeleteEvent   $event  The event instance.
     *
     * @return  void
     *
     * @since   3.1
     * @throws  \Exception on database error.
     */
    public function onFinderAfterDelete(FinderEvent\AfterDeleteEvent $event): void
    {
        $context = $event->getContext();
        $table   = $event->getItem();

        if ($context === 'com_jdocmanual.jdocmanual') {
            $id = $table->id;
        } elseif ($context === 'com_finder.index') {
            $id = $table->link_id;
        } else {
            return;
        }

        // Remove the items.
        $this->remove($id);
    }

    /**
     * Method to determine if the access level of an item changed.
     *
     * @param   FinderEvent\AfterSaveEvent   $event  The event instance.
     *
     * @return  void
     *
     * @since   3.1
     * @throws  \Exception on database error.
     */
    public function onFinderAfterSave(FinderEvent\AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $row     = $event->getItem();
        $isNew   = $event->getIsNew();

        // We only want to handle jdocmanual here.
        if ($context === 'com_jdocmanual.jdocmanual') {
            // Check if the access levels are different
            if (!$isNew && $this->old_access != $row->access) {
                // Process the change.
                $this->itemAccessChange($row);
            }

            // Reindex the item
            $this->reindex($row->id);
        }
    }

    /**
     * Method to reindex the link information for an item that has been saved.
     * This event is fired before the data is actually saved so we are going
     * to queue the item to be indexed later.
     *
     * @param   FinderEvent\BeforeSaveEvent   $event  The event instance.
     *
     * @return  void
     *
     * @since   3.1
     * @throws  \Exception on database error.
     */
    public function onFinderBeforeSave(FinderEvent\BeforeSaveEvent $event): void
    {
        $context = $event->getContext();
        $row     = $event->getItem();
        $isNew   = $event->getIsNew();

        // We only want to handle jdocmanual here
        if ($context === 'com_jdocmanual.jdocmanual') {
            // Query the database for the old access level if the item isn't new
            if (!$isNew) {
                $this->checkItemAccess($row);
            }
        }
    }

    /**
     * Method to update the link information for items that have been changed
     * from outside the edit screen. This is fired when the item is published,
     * unpublished, archived, or unarchived from the list view.
     *
     * @param   FinderEvent\AfterChangeStateEvent   $event  The event instance.
     *
     * @return  void
     *
     * @since   3.1
     */
    public function onFinderChangeState(FinderEvent\AfterChangeStateEvent $event): void
    {
        $context = $event->getContext();
        $pks     = $event->getPks();
        $value   = $event->getValue();

        // We only want to handle jdocmanual here
        if ($context === 'com_jdocmanual.jdocmanual') {
            $this->itemStateChange($pks, $value);
        }

        // Handle when the plugin is disabled
        if ($context === 'com_plugins.plugin' && $value === 0) {
            $this->pluginDisable($pks);
        }
    }

    /**
     * Method to index an item. The item must be a Result object.
     *
     * @param   Result  $item  The item to index as a Result object.
     *
     * @return  void
     *
     * @since   3.1
     * @throws  \Exception on database error.
     */
    protected function index(Result $item)
    {
        // Check if the extension is enabled
        if (ComponentHelper::isEnabled($this->extension) === false) {
            return;
        }

        $params = ComponentHelper::getParams('com_jdocmanual');
        $installation_subfolder = $params->get('installation_subfolder');

        $item->setLanguage();

        // Initialize the item parameters.
        $registry     = new Registry($item->params);
        $item->params = clone ComponentHelper::getParams('com_jdocmanual', true);
        $item->params->merge($registry);

        $item->metadata = new Registry($item->metadata);

        // Create a URL as identifier to recognise items again.
        $item->url = $this->getUrl($item->id, $this->extension, $this->layout);

        // Build the necessary route and path information.
        $item->route = $installation_subfolder . "/jdocmanual?article={$item->manual}/{$item->heading}/{$item->filename}";
        // was RouteHelper::getComponentJdocmanualRoute($item->slug, $item->language);

        // Get the menu title if it exists.
        $title = $this->getItemMenuTitle($item->url);

        // Adjust the title if necessary.
        if (!empty($title) && $this->params->get('use_menu_title', true)) {
            $item->title = $title;
        }

        // Metadata is not used in Jdocmanual now but it may be used in the future.

        // Add the meta author.
        $item->metaauthor = $item->metadata->get('author');

        // Handle the link to the metadata.
        $item->addInstruction(Indexer::META_CONTEXT, 'link');
        $item->addInstruction(Indexer::META_CONTEXT, 'metakey');
        $item->addInstruction(Indexer::META_CONTEXT, 'metadesc');
        $item->addInstruction(Indexer::META_CONTEXT, 'metaauthor');
        $item->addInstruction(Indexer::META_CONTEXT, 'author');
        $item->addInstruction(Indexer::META_CONTEXT, 'created_by_alias');

        // Get taxonomies to display
        $taxonomies = $this->params->get('taxonomies', ['type', 'language', 'manual']);

        // Add the type taxonomy data.
        if (\in_array('type', $taxonomies)) {
            $item->addTaxonomy('Type', 'Jdocmanual');
        }

        // Add the manual taxonomy data.
        if (\in_array('manual', $taxonomies) && (!empty($item->manual))) {
            $item->addTaxonomy('Manual', $item->mantitle);
        }

        // Add the language taxonomy data.
        if (\in_array('language', $taxonomies)) {
            $item->addTaxonomy('Language', $item->language);
        }

        // Get content extras.
        Helper::getContentExtras($item);

        // Index the item.
        $this->indexer->index($item);
    }

    /**
     * Method to setup the indexer to be run.
     *
     * @return  boolean  True on success.
     *
     * @since   3.1
     */
    protected function setup()
    {
        return true;
    }

    /**
     * Method to get the SQL query used to retrieve the list of content items.
     *
     * @param   mixed  $query  An object implementing QueryInterface or null.
     *
     * @return  QueryInterface  A database object.
     *
     * @since   3.1
     */
    protected function getListQuery($query = null)
    {
        $db = $this->getDatabase();

        // Check if we can use the supplied SQL query.
        $query = $query instanceof QueryInterface ? $query : $db->getQuery(true)
            ->select('a.id, a.display_title AS title, a.html AS body')
            ->select('a.state, b.locale AS language, a.manual, a.heading')
            ->select('(Select 1 AS test) AS access')
            ->select('LEFT(filename, LENGTH(filename) - 3) AS filename')
            ->select('c.title AS mantitle')
            ->from('#__jdm_articles AS a')
            ->leftjoin('#__jdm_languages AS b ON a.language = b.code')
            ->leftjoin('#__jdm_manuals AS c ON a.manual = c.manual');

        return $query;
    }

    /**
     * Method to get a SQL query to load the published and access states for the given article.
     *
     * @return  QueryInterface  A database object.
     *
     * @since   3.1
     */
    protected function getStateQuery()
    {
        $query = $this->getDatabase()->getQuery(true);
        $query->select($this->getDatabase()->quoteName('a.id'))
            ->select($this->getDatabase()->quoteName('a.' . $this->state_field, 'state') . ', ' . $this->getDatabase()->quoteName('a.access'))
            ->select('NULL AS cat_state, NULL AS cat_access')
            ->from($this->getDatabase()->quoteName($this->table, 'a'));

        return $query;
    }

    /**
     * Method to get the query clause for getting items to update by time.
     *
     * @param   string  $time  The modified timestamp.
     *
     * @return  QueryInterface  A database object.
     *
     * @since   3.1
     */
    protected function getUpdateQueryByTime($time)
    {
        // Build an SQL query based on the modified time.
        $query = $this->getDatabase()->getQuery(true)
            ->where('a.date >= ' . $this->getDatabase()->quote($time));

        return $query;
    }
}
