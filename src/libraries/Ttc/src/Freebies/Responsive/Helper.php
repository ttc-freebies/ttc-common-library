<?php

/**
 * @copyright   Copyright (C) 2021 Dimitrios Grammatikogiannis. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Ttc\Freebies\Responsive;

defined('_JEXEC') || die();

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\MediaHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Ttc\Intervention\Image\ImageManager;

/**
 * Content responsive images plugin
 */
class Helper
{
  private $separator    = '_';
  private $qualityJPG   = 75;
  private $qualityWEBP  = 60;
  private $qualityAVIF  = 40;
  private $scaleUp      = false;
  private $validSizes   = [200, 320, 480, 768, 992, 1200, 1600, 1920];
  private $validExt     = ['jpg', 'jpeg', 'png']; // 'webp', 'avif'

  public function __construct()
  {
    $this->enabled = PluginHelper::isEnabled('content', 'responsive');
    if ($this->enabled) {
      $plugin            = PluginHelper::getPlugin('content', 'responsive');
      $this->params      = new Registry($plugin->params);
      $this->enableWEBP  = (bool) $this->params->get('enableWEBP', 1);
      $this->enableAVIF  = (bool) $this->params->get('enableAVIF', 0);
      $this->qualityJPG  = (int) $this->params->get('qualityJPG', 75);
      $this->qualityWEBP = (int) $this->params->get('qualityWEBP', 60);
      $this->qualityAVIF = (int) $this->params->get('qualityAVIF', 40);
      $this->scaleUp     = (bool) $this->params->get('scaleUp', false);
      $this->separator   = $this->params->get('separator', '_');
      $excludeFolders    = preg_split('/[\s,]+/', $this->params->get('excludeFolders'));
      $sizes             = preg_split('/[\s,]+/', $this->params->get('sizes'));

      if (!is_array($sizes) || count($sizes) < 1) {
        $sizes = [200, 320, 480, 768, 992, 1200, 1600, 1920];
      }

      asort($sizes);
      $this->validSizes = $sizes;
      $this->excludeFolders = array_map(function ($folder) {
        return JPATH_ROOT . '/' . $folder;
      }, $excludeFolders);
    }
  }

  /**
   * Takes an image tag and returns the picture tag
   *
   * @param string  $image        the image tag
   * @param array   $breakpoints  the breakpoints
   *
   * @return string
   *
   * @throws \Exception
   */
  public function transformImage($image, array $breakpoints): string
  {
    // Bail out early
    if (!is_array($breakpoints) || !$this->enabled || strpos($image, '<img') === false) {
      return $image;
    }

    // Get the original path
    preg_match('/src\s*=\s*"(.+?)"/', $image, $match);
    if (count($match) < 2) {
      return $image;
    }

    $paths = $this->getPaths($match[1]);

    // Valid root path and not excluded path
    if (strpos($paths['pathReal'], JPATH_ROOT) !== 0 || strpos($paths['pathReal'], JPATH_ROOT) === false || in_array(dirname($paths['pathReal']), $this->excludeFolders)) {
      return $image;
    }

    $pathInfo = pathinfo($paths['path']);

    // Bail out if no images supported
    if (!in_array(mb_strtolower($pathInfo['extension']), $this->validExt) || !file_exists(JPATH_ROOT . $paths['path'])) {
      return $image;
    }

    if (!is_dir(JPATH_ROOT . '/media/cached-resp-images/' . str_replace('%20', ' ', $pathInfo['dirname']))) {
      if (
        !@mkdir(JPATH_ROOT . '/media/cached-resp-images/' . str_replace('%20', ' ', $pathInfo['dirname']), 0755, true)
        && !is_dir(JPATH_ROOT . '/media/cached-resp-images/' . str_replace('%20', ' ', $pathInfo['dirname']))
      ) {
        return $image;
      }
    }

    return $this->buildSrcset(
      [
        'dirname'   => str_replace('%20', ' ', $pathInfo['dirname']),
        'filename'  => str_replace('%20', ' ', $pathInfo['filename']),
        'extension' => $pathInfo['extension'],
        'tag'       => $image,
      ],
      $breakpoints,
    );
  }

  /**
   * Build the srcset string
   *
   * @param  array   $breakpoints  the different breakpoints
   * @param  array   $image        the image attributes, expects dirname, filename, extension
   *
   * @return string
   *
   * @since  1.0
   */
  private function buildSrcset(array $image, $breakpoints = [200, 320, 480, 768, 992, 1200, 1600, 1920]): string
  {
    if (empty($breakpoints) || !is_file(JPATH_ROOT . '/' . $image['dirname'] . '/' . $image['filename'] . '.' . $image['extension'])) {
      return $image['tag'];
    }

    if (!is_file(JPATH_ROOT . '/media/cached-resp-images/___data___/' . $image['dirname'] . '/' . $image['filename'] . '.json')) {
      $this->createImages(str_replace('%20', ' ', $image['dirname']), $image['filename'], $image['extension']);
    }

    try {
      $srcSets = \json_decode(
        @file_get_contents(JPATH_ROOT . '/media/cached-resp-images/___data___/' . $image['dirname'] . '/' . $image['filename'] . '.json')
      );
    } catch (\Exception $e) {
      return $image['tag'];
    }

    if (empty($srcSets) || $srcSets === false) {
      return $image['tag'];
    }

    $type   = in_array(mb_strtolower($image['extension']), ['jpg', 'jpeg']) ? 'jpeg' : mb_strtolower($image['extension']);
    $output = '<picture class="responsive-image">';

    if (!empty($srcSets->avif) && count(get_object_vars($srcSets->avif->srcset)) > 0) {
      $srcSetAvif = $this->getSrcSets($srcSets->avif->srcset, $breakpoints);
      if ($srcSetAvif !== '') {
        $output .= '<source type="image/avif" sizes="' . implode(', ', array_reverse($srcSets->base->sizes)) . '" srcset="' . $srcSetAvif . '">';
      }
    }

    if (!empty($srcSets->webp) && count(get_object_vars($srcSets->webp->srcset)) > 0) {
      $srcSetWebp = $this->getSrcSets($srcSets->webp->srcset, $breakpoints);
      if ($srcSetWebp !== '') {
        $output .= '<source type="image/webp" sizes="' . implode(', ', array_reverse($srcSets->base->sizes)) . '" srcset="' . $srcSetWebp . '">';
      }
    }

    $srcSetOrig = $this->getSrcSets($srcSets->base->srcset, $breakpoints);
    if ($srcSetOrig !== '') {
      $output .= '<source type="image/' . $type . '" sizes="' . implode(', ', array_reverse($srcSets->base->sizes)) . '" srcset="' . $srcSetOrig . '">';
    }

    // Create the fallback img
    $fallBack = preg_replace(
      [
        '/src\s*=\s*".+?"/',
        '/width\s*=\s*".+?"/',
        '/height\s*=\s*".+?"/',
      ],
      [
        'src="/media/cached-resp-images/' . $image['dirname'] . '/' . $image['filename'] . $this->separator . $this->validSizes[count($this->validSizes) - 1] . '.' . $image['extension'] . '?version=' . $srcSets->base->version . '"',
        'width="' . $srcSets->base->width . '"',
        'height="' . $srcSets->base->height . '"',
      ],
      $image['tag']
    );

    if (preg_match('/width\s*=\s*".+?"/', $fallBack) === 0) {
      $fallBack = str_replace('<img ', '<img width="' . $srcSets->base->width . '" ', $fallBack);
    }

    if (preg_match('/height\s*=\s*".+?"/', $fallBack) === 0) {
      $fallBack = str_replace('<img ', '<img height="' . $srcSets->base->height . '" ', $fallBack);
    }

    if (preg_match('/loading\s*=\s*".+?"/', $fallBack) === 0) {
      $fallBack = str_replace('<img ', '<img loading="lazy" ', $fallBack);
    }

    if (preg_match('/decoding\s*=\s*".+?"/', $fallBack) === 0) {
      $fallBack = str_replace('<img ', '<img decoding="async" ', $fallBack);
    }

    return  $output . $fallBack . '</picture>';
  }

  /**
   * Create the thumbs
   *
   * @param string   $dirname      the folder name
   * @param string   $filename     the file name
   * @param string   $extension    the file extension
   *
   * @return void
   *
   * @since  1.0
   */
  private function createImages($dirname, $filename, $extension)
  {
    if (extension_loaded('imagick')) {
      $driver = 'imagick';
    } elseif (extension_loaded('gd')) {
      $driver = 'gd';
    }

    if (!isset($driver)) {
      return;
    }

    // Create the images with width = breakpoint
    $manager = new ImageManager($driver);
    // remove the first slash
    $dirname = ltrim($dirname, '/');

    // Getting the image info
    $info = @getimagesize(JPATH_ROOT . '/' . $dirname . '/' . $filename . '.' . $extension);
    $hash = hash_file('md5', JPATH_ROOT . '/' . $dirname . '/' . $filename . '.' . $extension);

    if (empty($info)) {
      return;
    }

    $imageWidth = $info[0];
    $imageHeight = $info[1];

    // Skip if the width is less or equal to the required
    if ($imageWidth <= (int) $this->validSizes[0]) {
      return;
    }

    // Check if we support the given image
    if (!in_array($info['mime'], ['image/jpeg', 'image/jpg', 'image/png'])) {
      return;
    }

    $srcSets = new \stdClass;
    $srcSets->base = new \stdClass;
    $srcSets->base->srcset = [];
    $srcSets->base->sizes = [];
    $srcSets->base->width = $info[0];
    $srcSets->base->height = $info[1];
    $srcSets->base->version = $hash;

    $channels = isset($info['channels']) ? $info['channels'] : 4;

    if (!isset($info['bits'])) {
      $info['bits'] = 16;
    }

    $imageBits = ($info['bits'] / 8) * $channels;

    // Do some memory checking
    if (!self::checkMemoryLimit(['width' => $imageWidth, 'height' => $imageHeight, 'bits' => $imageBits], $dirname . '/' . $filename . '.' . $extension)) {
      return;
    }

    array_push($srcSets->base->sizes, '(max-width: ' . $this->validSizes[count($this->validSizes) - 1] . 'px) 100vw ' . $this->validSizes[count($this->validSizes) - 1] . 'px');
    for ($i = 0, $l = count($this->validSizes); $i < $l; $i++) {
      if ($this->scaleUp || ($imageWidth >= (int) $this->validSizes[$i])) {
        // Load the image
        $image = $manager->make(JPATH_ROOT . '/' . $dirname . '/' . $filename . '.' . $extension);
        $fileSrc = 'media/cached-resp-images/' . $dirname . '/' . $filename . trim($this->separator) . trim($this->validSizes[$i]);

        // Resize the image
        $image->resize($this->validSizes[$i], null, function ($constraint) {
          $constraint->aspectRatio();
          $constraint->upsize();
        });

        // Save the image
        if ($info['mime'] === 'image/jpeg')
          $image->toJpeg($this->qualityJPG)->save(JPATH_ROOT . '/' . $fileSrc . '.' . $extension);
        else
          $image->toPng($this->qualityJPG)->save(JPATH_ROOT . '/' . $fileSrc . '.' . $extension);

        $srcSets->base->srcset[$this->validSizes[$i]] = str_replace(' ', '%20', $fileSrc) . '.' . $extension . '?version=' . $hash . ' ' . $this->validSizes[$i] . 'w';

        if ($this->enableWEBP && (($driver === 'imagick' && \Imagick::queryFormats('WEBP')) || ($driver === 'gd' && function_exists('imagewebp')))) {
          // Save the image as webp
          $this->createImage($image, $fileSrc, 'webp', $this->qualityWEBP, $srcSets, $hash, $this->validSizes[$i], 'toWebp');
        }

        if ($this->enableAVIF && (($driver === 'imagick' && \Imagick::queryFormats('AVIF')) || ($driver === 'gd' && function_exists('imageavif')))) {
          // Save the image as avif
          $this->createImage($image, $fileSrc, 'avif', $this->qualityAVIF, $srcSets, $hash, $this->validSizes[$i], 'toAvif');
        }

        $image->destroy();
        \gc_collect_cycles();
      }
    }


    if (!is_dir(JPATH_ROOT . '/media/cached-resp-images/___data___/' . $dirname)) {
      mkdir(JPATH_ROOT . '/media/cached-resp-images/___data___/' . $dirname, 0755, true);
    }
    file_put_contents(JPATH_ROOT . '/media/cached-resp-images/___data___/' . $dirname . '/' . $filename . '.json', \json_encode($srcSets));
  }

  /**
   * Check memory boundaries
   *
   * @param object  $properties   the Image properties object
   * @param string  $imagePath    the image path
   *
   * @return bool
   *
   * @since  3.0.3
   *
   * @author  Niels Nuebel: https://github.com/nielsnuebel
   */
  private static function checkMemoryLimit($properties, $imagePath): bool
  {
    $memorycheck = ($properties['width'] * $properties['height'] * $properties['bits']);
    $memorycheck_text = $memorycheck / (1024 * 1024);
    $memory_limit = ini_get('memory_limit');

    if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
      if ($matches[2] == 'M') {
        $memory_limit_value = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
      } else if ($matches[2] == 'K') {
        $memory_limit_value = $matches[1] * 1024; // nnnK -> nnn KB
      }
    }

    if (isset($memory_limit_value) && $memorycheck > $memory_limit_value) {
      Factory::getApplication()->enqueueMessage('Image too big to be processed', 'error'); //, $imagePath, $memorycheck_text, $memory_limit

      return false;
    }

    return true;
  }

  /**
   * Helper, creates a new obj
   *
   * @param string $type
   * @param object $obj
   */
  private function createObject($type, $obj)
  {
    $obj->{$type} = new \stdClass;
    $obj->{$type}->srcset = [];

    return $obj;
  }

  /**
   * Helper, creates an image
   *
   * @param \Ttc\FreebiesIntervention\Image\ImageManager  $image
   * @param string  $fileSrc
   * @param string  $imageType
   * @param int     $quality
   * @param object  $srcSets
   * @param string  $hash
   * @param int     $size
   */
  private function createImage($image, $fileSrc, $imageType, $quality, $srcSets, $hash, $size, $as)
  {
    // Save the image as avif
    $image->{$as}($quality)->save(JPATH_ROOT . '/' . $fileSrc . '.' . $imageType);
    if (!isset($srcSets->{$imageType})) $srcSets = $this->createObject($imageType, $srcSets, $hash);
    $srcSets->{$imageType}->srcset[$size] = str_replace(' ', '%20', $fileSrc) . '.' . $imageType . '?version=' . $hash . ' ' . $size . 'w';
  }

  /**
   * Helper, returns the srcsets from the object for the given breakpoints
   *
   * @param object $obj
   * @param array  $breakpoints
   */
  private function getSrcSets($obj, $breakpoints): string
  {
    $retArr = [];
    foreach ($obj as $key => $val) {
      if (in_array($key, $breakpoints)) {
        $retArr[] = $val;
      }
    }

    if (count($retArr) > 0) {
      return implode(', ', array_reverse($retArr));
    }
    return '';
  }

  private function getPaths($path)
  {
    $path = MediaHelper::getCleanMediaFieldValue(str_replace(Uri::base(), '', $path));
    $path = (substr($path, 0, 1) === '/' ? $path : '/' . $path);

    return [
      'path' => str_replace('%20', ' ', $path),
      'pathReal' => realpath(JPATH_ROOT . str_replace('%20', ' ', $path)),
    ];
  }
}
