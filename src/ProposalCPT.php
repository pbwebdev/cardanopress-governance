<?php

/**
 * @package ThemePlate
 * @since   0.1.0
 */

namespace PBWebDev\CardanoPress\Governance;

use Exception;
use Monolog\Logger;
use ThemePlate\CPT\PostType;
use WP_Post;
use WP_Query;

class ProposalCPT
{
    protected Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;

        add_action('wp_insert_post', [$this, 'prepareData'], 10, 2);
        add_action('wp_insert_post', [$this, 'scheduleSnapshot'], 10, 2);
        add_filter('pre_get_posts', [$this, 'customizeStatus']);
        add_filter('use_block_editor_for_post_type', [$this, 'noBlocks'], 10, 2);
    }

    public function register(): void
    {
        try {
            new PostType([
                'name' => 'proposal',
                'plural' => __('Proposals', 'cardanopress-governance'),
                'singular' => __('Proposal', 'cardanopress-governance'),
                'args' => [
                    'menu_position' => 5,
                    'menu_icon' => 'dashicons-feedback',
                    'supports' => ['title', 'editor', 'excerpt'],
                    'has_archive' => true,
                    'rewrite' => ['slug' => 'proposals'],
                    'rest_base' => 'proposals',
                ],
            ]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
        }
    }

    public function prepareData(int $postId, WP_Post $post): void
    {
        if ('proposal' !== $post->post_type) {
            return;
        }

        $options = get_post_meta($postId, 'proposal_options', false);
        $data = get_post_meta($postId, '_proposal_data', true) ?: [];
        $updated = false;

        foreach ($options as $option) {
            if (array_key_exists($option['value'], $data)) {
                continue;
            }

            $data[$option['value']] = 0;
            $updated = true;
        }

        $different = array_diff(array_keys($data), array_column($options, 'value'));

        if ($different) {
            $updated = true;

            foreach ($different as $old) {
                unset($data[$old]);
            }
        }

        if ($updated) {
            update_post_meta($postId, '_proposal_data', $data);
        }
    }

    public function scheduleSnapshot(int $postId, WP_Post $post): void
    {
        if ('proposal' !== $post->post_type) {
            return;
        }

        $snapshot = get_post_meta($postId, 'proposal_snapshot', true);

        if (! $snapshot || Snapshot::isScheduled($postId) || Snapshot::wasScheduled($postId)) {
            return;
        }

        $difference = get_option('gmt_offset') * HOUR_IN_SECONDS;
        $timestamp = strtotime(implode(' ', $snapshot));

        Snapshot::schedule($timestamp - $difference, $postId);
    }

    public function customizeStatus(WP_Query $query): void
    {
        if (
            $query->get_queried_object() &&
            ! $query->is_post_type_archive('proposal') &&
            ! $query->is_singular('proposal')
        ) {
            return;
        }

        global $wp_post_statuses;

        $future = &$wp_post_statuses['future'];

        $future->public = true;
        $future->protected = false;
    }

    public function noBlocks(bool $status, string $postType): bool
    {
        if ('proposal' === $postType) {
            $status = false;
        }

        return $status;
    }
}
