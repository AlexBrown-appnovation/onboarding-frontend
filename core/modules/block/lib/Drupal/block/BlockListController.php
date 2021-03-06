<?php

/**
 * @file
 * Contains \Drupal\block\BlockListController.
 */

namespace Drupal\block;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\Json;
use Drupal\Component\Utility\String;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityListController;
use Drupal\Core\Entity\EntityControllerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageControllerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines the block list controller.
 */
class BlockListController extends ConfigEntityListController implements FormInterface, EntityControllerInterface {

  /**
   * The regions containing the blocks.
   *
   * @var array
   */
  protected $regions;

  /**
   * The theme containing the blocks.
   *
   * @var string
   */
  protected $theme;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The block manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $blockManager;

  /**
   * Constructs a new BlockListController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageControllerInterface $storage
   *   The entity storage controller class.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $block_manager
   *   The block manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageControllerInterface $storage, PluginManagerInterface $block_manager) {
    parent::__construct($entity_type, $storage);

    $this->blockManager = $block_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity.manager')->getStorageController($entity_type->id()),
      $container->get('plugin.manager.block')
    );
  }

  /**
   * Overrides \Drupal\Core\Config\Entity\ConfigEntityListController::load().
   */
  public function load() {
    // If no theme was specified, use the current theme.
    if (!$this->theme) {
      $this->theme = $GLOBALS['theme'];
    }

    // Store the region list.
    $this->regions = system_region_list($this->theme, REGIONS_VISIBLE);

    // Load only blocks for this theme, and sort them.
    // @todo Move the functionality of _block_rehash() out of the listing page.
    $entities = _block_rehash($this->theme);

    // Sort the blocks using \Drupal\block\Entity\Block::sort().
    uasort($entities, array($this->entityType->getClass(), 'sort'));
    return $entities;
  }

  /**
   * Overrides \Drupal\Core\Entity\EntityListController::render().
   *
   * @param string|null $theme
   *   (optional) The theme to display the blocks for. If NULL, the current
   *   theme will be used.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   The block list as a renderable array.
   */
  public function render($theme = NULL, Request $request = NULL) {
    $this->request = $request;
    // If no theme was specified, use the current theme.
    $this->theme = $theme ?: $GLOBALS['theme_key'];

    return drupal_get_form($this);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'block_admin_display_form';
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::buildForm().
   *
   * Form constructor for the main block administration form.
   */
  public function buildForm(array $form, array &$form_state) {
    $placement = FALSE;
    if ($this->request->query->has('block-placement')) {
      $placement = $this->request->query->get('block-placement');
      $form['#attached']['js'][] = array(
        'type' => 'setting',
        'data' => array('blockPlacement' => $placement),
      );
    }
    $entities = $this->load();
    $form['#theme'] = array('block_list');
    $form['#attached']['library'][] = array('core', 'drupal.tableheader');
    $form['#attached']['library'][] = array('block', 'drupal.block');
    $form['#attached']['library'][] = array('block', 'drupal.block.admin');
    $form['#attributes']['class'][] = 'clearfix';

    // Add a last region for disabled blocks.
    $block_regions_with_disabled = $this->regions + array(BlockInterface::BLOCK_REGION_NONE => BlockInterface::BLOCK_REGION_NONE);
    $form['block_regions'] = array(
      '#type' => 'value',
      '#value' => $block_regions_with_disabled,
    );

    // Weights range from -delta to +delta, so delta should be at least half
    // of the amount of blocks present. This makes sure all blocks in the same
    // region get an unique weight.
    $weight_delta = round(count($entities) / 2);

    // Build the form tree.
    $form['edited_theme'] = array(
      '#type' => 'value',
      '#value' => $this->theme,
    );
    $form['blocks'] = array(
      '#type' => 'table',
      '#header' => array(
        t('Block'),
        t('Category'),
        t('Region'),
        t('Weight'),
        t('Operations'),
      ),
      '#attributes' => array(
        'id' => 'blocks',
      ),
    );

    // Build blocks first for each region.
    foreach ($entities as $entity_id => $entity) {
      $definition = $entity->getPlugin()->getPluginDefinition();
      $blocks[$entity->get('region')][$entity_id] = array(
        'label' => $entity->label(),
        'entity_id' => $entity_id,
        'weight' => $entity->get('weight'),
        'entity' => $entity,
        'category' => $definition['category'],
      );
    }

    // Loop over each region and build blocks.
    foreach ($block_regions_with_disabled as $region => $title) {
      $form['blocks']['#tabledrag'][] = array(
        'action' => 'match',
        'relationship' => 'sibling',
        'group' => 'block-region-select',
        'subgroup' => 'block-region-' . $region,
        'hidden' => FALSE,
      );
      $form['blocks']['#tabledrag'][] = array(
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'block-weight',
        'subgroup' => 'block-weight-' . $region,
      );

      $form['blocks'][$region] = array(
        '#attributes' => array(
          'class' => array('region-title', 'region-title-' . $region),
          'no_striping' => TRUE,
        ),
      );
      $form['blocks'][$region]['title'] = array(
        '#markup' => $region != BlockInterface::BLOCK_REGION_NONE ? $title : t('Disabled'),
        '#wrapper_attributes' => array(
          'colspan' => 5,
        ),
      );

      $form['blocks'][$region . '-message'] = array(
        '#attributes' => array(
          'class' => array(
            'region-message',
            'region-' . $region . '-message',
            empty($blocks[$region]) ? 'region-empty' : 'region-populated',
          ),
        ),
      );
      $form['blocks'][$region . '-message']['message'] = array(
        '#markup' => '<em>' . t('No blocks in this region') . '</em>',
        '#wrapper_attributes' => array(
          'colspan' => 5,
        ),
      );

      if (isset($blocks[$region])) {
        foreach ($blocks[$region] as $info) {
          $entity_id = $info['entity_id'];

          $form['blocks'][$entity_id] = array(
            '#attributes' => array(
              'class' => array('draggable'),
            ),
          );
          if ($placement && $placement == drupal_html_class($entity_id)) {
            $form['blocks'][$entity_id]['#attributes']['id'] = 'block-placed';
          }

          $form['blocks'][$entity_id]['info'] = array(
            '#markup' => String::checkPlain($info['label']),
            '#wrapper_attributes' => array(
              'class' => array('block'),
            ),
          );
          $form['blocks'][$entity_id]['type'] = array(
            '#markup' => $info['category'],
          );
          $form['blocks'][$entity_id]['region-theme']['region'] = array(
            '#type' => 'select',
            '#default_value' => $region,
            '#empty_value' => BlockInterface::BLOCK_REGION_NONE,
            '#title' => t('Region for @block block', array('@block' => $info['label'])),
            '#title_display' => 'invisible',
            '#options' => $this->regions,
            '#attributes' => array(
              'class' => array('block-region-select', 'block-region-' . $region),
            ),
            '#parents' => array('blocks', $entity_id, 'region'),
          );
          $form['blocks'][$entity_id]['region-theme']['theme'] = array(
            '#type' => 'hidden',
            '#value' => $this->theme,
            '#parents' => array('blocks', $entity_id, 'theme'),
          );
          $form['blocks'][$entity_id]['weight'] = array(
            '#type' => 'weight',
            '#default_value' => $info['weight'],
            '#delta' => $weight_delta,
            '#title' => t('Weight for @block block', array('@block' => $info['label'])),
            '#title_display' => 'invisible',
            '#attributes' => array(
              'class' => array('block-weight', 'block-weight-' . $region),
            ),
          );
          $form['blocks'][$entity_id]['operations'] = $this->buildOperations($info['entity']);
        }
      }
    }

    // Do not allow disabling the main system content block when it is present.
    if (isset($form['blocks']['system_main']['region'])) {
      $form['blocks']['system_main']['region']['#required'] = TRUE;
    }

    $form['actions'] = array(
      '#tree' => FALSE,
      '#type' => 'actions',
    );
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Save blocks'),
      '#button_type' => 'primary',
    );

    $form['place_blocks']['title'] = array(
      '#type' => 'container',
      '#children' => '<h3>' . t('Place blocks') . '</h3>',
      '#attributes' => array(
        'class' => array(
          'entity-meta-header',
        ),
      ),
    );

    $form['place_blocks']['filter'] = array(
      '#type' => 'search',
      '#title' => t('Filter'),
      '#title_display' => 'invisible',
      '#size' => 30,
      '#placeholder' => t('Filter by block name'),
      '#attributes' => array(
        'class' => array('block-filter-text'),
        'data-element' => '.entity-meta',
        'title' => t('Enter a part of the block name to filter by.'),
      ),
    );

    $form['place_blocks']['list']['#type'] = 'container';
    $form['place_blocks']['list']['#attributes']['class'][] = 'entity-meta';

    // Sort the plugins first by category, then by label.
    $plugins = $this->blockManager->getDefinitions();
    uasort($plugins, function ($a, $b) {
      if ($a['category'] != $b['category']) {
        return strnatcasecmp($a['category'], $b['category']);
      }
      return strnatcasecmp($a['admin_label'], $b['admin_label']);
    });
    foreach ($plugins as $plugin_id => $plugin_definition) {
      $category = String::checkPlain($plugin_definition['category']);
      $category_key = 'category-' . $category;
      if (!isset($form['place_blocks']['list'][$category_key])) {
        $form['place_blocks']['list'][$category_key] = array(
          '#type' => 'details',
          '#title' => $category,
          'content' => array(
            '#theme' => 'links',
            '#links' => array(),
            '#attributes' => array(
              'class' => array(
                'block-list',
              ),
            ),
          ),
        );
      }
      $form['place_blocks']['list'][$category_key]['content']['#links'][$plugin_id] = array(
        'title' => $plugin_definition['admin_label'],
        'href' => 'admin/structure/block/add/' . $plugin_id . '/' . $this->theme,
        'attributes' => array(
          'class' => array('use-ajax', 'block-filter-text-source'),
          'data-accepts' => 'application/vnd.drupal-modal',
          'data-dialog-options' => Json::encode(array(
            'width' => 700,
          )),
        ),
      );
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity) {
    $operations = parent::getOperations($entity);

    if (isset($operations['edit'])) {
      $operations['edit']['title'] = t('Configure');
    }

    return $operations;
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::validateForm().
   */
  public function validateForm(array &$form, array &$form_state) {
    // No validation.
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::submitForm().
   *
   * Form submission handler for the main block administration form.
   */
  public function submitForm(array &$form, array &$form_state) {
    $entities = entity_load_multiple('block', array_keys($form_state['values']['blocks']));
    foreach ($entities as $entity_id => $entity) {
      $entity->set('weight', $form_state['values']['blocks'][$entity_id]['weight']);
      $entity->set('region', $form_state['values']['blocks'][$entity_id]['region']);
      if ($entity->get('region') == BlockInterface::BLOCK_REGION_NONE) {
        $entity->disable();
      }
      else {
        $entity->enable();
      }
      $entity->save();
    }
    drupal_set_message(t('The block settings have been updated.'));
    Cache::invalidateTags(array('content' => TRUE));
  }

}
