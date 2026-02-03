<?php

namespace ImageFocus;

/**
 * The class responsible for cropping the attachments
 *
 * Class CropService
 * @package ImageFocus
 */
class CropService
{
	private $attachment = [];
	private $imageSizes = [];
	private $focusPoint = ['x' => 50, 'y' => 50];

	/**
	 * Crop the image on base of the focus point
	 *
	 * @param $attachmentId
	 * @param $focusPoint
	 * @return bool
	 */
	public function crop($attachmentId, $focusPoint)
	{
		// Set all the cropping data
		$this->setCropData($attachmentId, $focusPoint);

		// If we don't have a valid attachment size, stop here
		if (empty($this->attachment['width']) || empty($this->attachment['height'])) {
			return false;
		}

		$this->cropAttachment();

		return true;
	}

	/**
	 * Set all crop data
	 *
	 * @param $attachmentId
	 * @param $focusPoint
	 */
	private function setCropData($attachmentId, $focusPoint)
	{
		$this->getImageSizes();
		$this->getAttachment($attachmentId);
		$this->setFocusPoint($focusPoint);

		// Only save focus point if we have a valid attachment ID
		if (!empty($this->attachment['id'])) {
			$this->saveFocusPointToDB();
		}
	}

	/**
	 * Get all the image sizes excluding the ones that don't need cropping
	 *
	 * @return $this
	 */
	public function getImageSizes()
	{
		// Get all the default WordPress image Sizes
		foreach ((array) get_intermediate_image_sizes() as $imageSize) {
			if (
				in_array($imageSize, ['thumbnail', 'medium', 'medium_large', 'large'], true)
				&& get_option("{$imageSize}_crop")
			) {
				$w = (int) get_option("{$imageSize}_size_w");
				$h = (int) get_option("{$imageSize}_size_h");

				$this->imageSizes[$imageSize] = [
					'width'	=> $w,
					'height'	=> $h,
					'crop'	=> (bool) get_option("{$imageSize}_crop"),
					'ratio'	=> ($h > 0) ? ((float) $w / $h) : 0.0
				];
			}
		}

		// Get all the custom set image Sizes
		foreach ((array) wp_get_additional_image_sizes() as $key => $imageSize) {
			if (!empty($imageSize['crop'])) {
				$w = isset($imageSize['width']) ? (int) $imageSize['width'] : 0;
				$h = isset($imageSize['height']) ? (int) $imageSize['height'] : 0;

				$this->imageSizes[$key] = $imageSize;
				$this->imageSizes[$key]['width'] = $w;
				$this->imageSizes[$key]['height'] = $h;
				$this->imageSizes[$key]['ratio'] = ($h > 0) ? ((float) $w / $h) : 0.0;
			}
		}

		return $this;
	}

	/**
	 * Return the src array of the attachment image containing url, width & height
	 *
	 * @param $attachmentId
	 * @return $this
	 */
	private function getAttachment($attachmentId)
	{
		$attachment = wp_get_attachment_image_src($attachmentId, 'full');

		// wp_get_attachment_image_src returns false if the file is missing / not an image / corrupt
		if (!$attachment || !is_array($attachment)) {
			$this->attachment = [
				'id'	=> (int) $attachmentId,
				'src'	=> '',
				'width'	=> 0,
				'height'	=> 0,
				'ratio'	=> 0.0
			];

			return $this;
		}

		$src = isset($attachment[0]) ? (string) $attachment[0] : '';
		$width = isset($attachment[1]) ? (int) $attachment[1] : 0;
		$height = isset($attachment[2]) ? (int) $attachment[2] : 0;

		$this->attachment = [
			'id'	=> (int) $attachmentId,
			'src'	=> $src,
			'width'	=> $width,
			'height'	=> $height,
			'ratio'	=> ($height > 0) ? ((float) $width / $height) : 0.0
		];

		return $this;
	}

	/**
	 * Set the focuspoint for the crop
	 *
	 * @param $focusPoint
	 * @return $this
	 */
	private function setFocusPoint($focusPoint)
	{
		if ($focusPoint && is_array($focusPoint)) {
			if (isset($focusPoint['x'], $focusPoint['y'])) {
				$this->focusPoint = [
					'x'	=> max(0, min(100, (float) $focusPoint['x'])),
					'y'	=> max(0, min(100, (float) $focusPoint['y']))
				];
			}
		}

		return $this;
	}

	/**
	 * Put the focuspoint in the post meta of the attachment post
	 */
	private function saveFocusPointToDB()
	{
		update_post_meta($this->attachment['id'], 'focus_point', $this->focusPoint);
	}

	/**
	 * Crop the actual attachment
	 */
	private function cropAttachment()
	{
		// If attachment data is invalid, stop
		if (empty($this->attachment['width']) || empty($this->attachment['height']) || empty($this->attachment['ratio'])) {
			return;
		}

		// Loop through all the image sizes connected to this attachment
		foreach ($this->imageSizes as $imageSize) {

			// Skip invalid imageSize definitions
			if (empty($imageSize['width']) || empty($imageSize['height']) || empty($imageSize['ratio'])) {
				continue;
			}

			// Stop this iteration if the attachment is too small to be cropped for this image size
			if ($imageSize['width'] > $this->attachment['width'] || $imageSize['height'] > $this->attachment['height']) {
				continue;
			}

			// Get the file path of the attachment and then delete the old image
			$imageFilePath = $this->getImageFilePath($imageSize);

			if (!empty($imageFilePath)) {
				$this->removeOldImage($imageFilePath);
			}

			// Now execute the actual image crop
			$this->cropImage($imageSize, $imageFilePath);
		}
	}

	/**
	 * Get the file destination based on the attachment in the argument
	 *
	 * @param $imageSize
	 * @return mixed
	 */
	private function getImageFilePath($imageSize)
	{
		$upload = wp_upload_dir();
		$baseDir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';

		if ($baseDir === '') {
			return '';
		}

		$uploadDir = rtrim($baseDir, '/') . '/';

		// Get the attachment name
		$attachedFile = get_post_meta($this->attachment['id'], '_wp_attached_file', true);

		if (empty($attachedFile) || !is_string($attachedFile)) {
			return '';
		}

		$pi = pathinfo($attachedFile);
		$attachment = isset($pi['filename']) ? (string) $pi['filename'] : '';

		if ($attachment === '') {
			return '';
		}

		$croppedAttachment = $attachment . '-' . (int) $imageSize['width'] . 'x' . (int) $imageSize['height'];

		// Add the image size to the name of the attachment
		$fileName = str_replace($attachment, $croppedAttachment, $attachedFile);

		return $uploadDir . $fileName;
	}

	/**
	 * Remove the old attachment
	 *
	 * @param $file
	 */
	private function removeOldImage($file)
	{
		if (is_string($file) && $file !== '' && file_exists($file)) {
			@unlink($file);
		}
	}

	/**
	 * Calculate all the positions necessary to crop the image and crop it.
	 *
	 * @param $imageSize
	 * @param $imageFilePath
	 * @return $this
	 */
	private function cropImage($imageSize, $imageFilePath)
	{
		// Safety: ensure ratios are valid
		if (empty($imageSize['ratio']) || empty($this->attachment['ratio'])) {
			return $this;
		}

		// Gather all dimension
		$dimensions = ['x' => [], 'y' => []];
		$directions = ['x' => 'width', 'y' => 'height'];

		// Define the correction the image needs to keep the same ratio after the cropping has taken place
		$cropCorrection = [
			'x'	=> $imageSize['ratio'] / $this->attachment['ratio'],
			'y'	=> $this->attachment['ratio'] / $imageSize['ratio']
		];

		// Check all the cropping values
		foreach ($dimensions as $axis => $dimension) {

			$full = $this->attachment[$directions[$axis]];

			if ($full <= 0) {
				return $this;
			}

			// Get the center position
			$dimensions[$axis]['center'] = $this->focusPoint[$axis] / 100 * $full;
			// Get the starting position and let's correct the crop ratio
			$dimensions[$axis]['start'] = $dimensions[$axis]['center'] - $full * $cropCorrection[$axis] / 2;
			// Get the ending position and let's correct the crop ratio
			$dimensions[$axis]['end'] = $dimensions[$axis]['center'] + $full * $cropCorrection[$axis] / 2;

			// Is the start position lower than 0? That's not possible so let's correct it
			if ($dimensions[$axis]['start'] < 0) {
				$dimensions[$axis]['end'] = min(
					$dimensions[$axis]['end'] - $dimensions[$axis]['start'],
					$full
				);
				$dimensions[$axis]['start'] = 0;
			}

			// Is the end position higher than the total image size? That's not possible so let's correct it
			if ($dimensions[$axis]['end'] > $full) {
				$dimensions[$axis]['start'] = max(
					$dimensions[$axis]['start'] + $full - $dimensions[$axis]['end'],
					0
				);
				$dimensions[$axis]['end'] = min(
					$dimensions[$axis]['end'] + $full - $dimensions[$axis]['end'],
					$full
				);
			}
		}

		$cropW = $dimensions['x']['end'] - $dimensions['x']['start'];
		$cropH = $dimensions['y']['end'] - $dimensions['y']['start'];

		// Safety: wp_crop_image needs positive crop dimensions
		if ($cropW <= 0 || $cropH <= 0) {
			return $this;
		}

		// Execute the WordPress image crop function
		wp_crop_image(
			$this->attachment['id'],
			$dimensions['x']['start'],
			$dimensions['y']['start'],
			$cropW,
			$cropH,
			(int) $imageSize['width'],
			(int) $imageSize['height'],
			false,
			$imageFilePath
		);

		return $this;
	}
}
