<?php
/**
 * A blog category, a tag and two related posts, the first linked to product
 * "simple", so every nested blog resolver has a row to resolve.
 *
 * No-op without Magenx_Blog: the blog cases are then skipped anyway.
 *
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

if (!class_exists(\Magenx\Blog\Model\PostRepository::class)) {
    return;
}

$objectManager = Bootstrap::getObjectManager();

$category = $objectManager->create(\Magenx\Blog\Model\Category::class)
    ->setData(['name' => 'State test category', 'url_key' => 'state-test-category', 'is_active' => 1]);
$objectManager->get(\Magenx\Blog\Model\CategoryRepository::class)->save($category);

$tag = $objectManager->create(\Magenx\Blog\Model\Tag::class)
    ->setData(['name' => 'State test tag', 'url_key' => 'state-test-tag']);
$objectManager->get(\Magenx\Blog\Model\TagRepository::class)->save($tag);

$postRepository = $objectManager->get(\Magenx\Blog\Model\PostRepository::class);
$productId = (int) $objectManager->get(ProductRepositoryInterface::class)->get('simple')->getId();
$publishDate = gmdate('Y-m-d H:i:s', time() - 3600);

$related = $objectManager->create(\Magenx\Blog\Model\Post::class)->setData([
    'title' => 'State test related post',
    'url_key' => 'state-test-post-related',
    'content' => 'Related',
    'is_active' => 1,
    'publish_date' => $publishDate,
    'store_ids' => [0],
    'category_ids' => [(int) $category->getId()],
    'tag_ids' => [(int) $tag->getId()],
]);
$postRepository->save($related);

$post = $objectManager->create(\Magenx\Blog\Model\Post::class)->setData([
    'title' => 'State test post',
    'url_key' => 'state-test-post',
    'content' => 'Body',
    'is_active' => 1,
    'publish_date' => $publishDate,
    'store_ids' => [0],
    'category_ids' => [(int) $category->getId()],
    'tag_ids' => [(int) $tag->getId()],
    'product_positions' => [$productId => 1],
    'related_post_positions' => [(int) $related->getId() => 1],
]);
$postRepository->save($post);
