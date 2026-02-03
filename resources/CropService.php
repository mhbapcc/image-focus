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
        $this->cropAttachment();
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
        $this->saveFocusPointToDB();
    }

    /**
     * Get all the image sizes excluding the ones that don't need cropping
     *
     * @return $this
     */
    public function getImageSizes()
    {
        // Get all the default WordPress image Sizes
        foreach ((array)get_intermediate_image_sizes() as $imageSize) {
            if (in_array($imageSize, ['thumbnail', 'medium', 'medium_large', 'large'], true)
                && get_option("{$imageSize}_crop")
            ) {
                $this->imageSizes[$imageSize] = [
                    'width'  => (int)get_option("{$imageSize}_size_w"),
                    'height' => (int)get_option("{$imageSize}_size_h"),
                    'crop'   => (bool)get_option("{$imageSize}_crop"),
                    'ratio'  => (float)get_option("{$imageSize}_size_w") / (int)get_option("{$imageSize}_size_h")
                ];
            }
        }

        // Get all the custom set image Sizes
        foreach ((array)wp_get_additional_image_sizes() as $key => $imageSize) {
            if ($imageSize['crop']) {
                $this->imageSizes[$key] = $imageSize;
                $this->imageSizes[$key]['ratio'] = (float)$imageSize['width'] / $imageSize['height'];
            }
        }

        return $this;
    }

    /**
     *  Return the src array of the attachment image containing url, width & height
     *
     * @param $attachmentId
     * @return $this
     */
    private function getAttachment($attachmentId)
    {
    	$attachment = wp_get_attachment_image_src($attachmentId, 'full');
    
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
        if ($focusPoint) {
            $this->focusPoint = $focusPoint;
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
    	if (empty($this->attachment['width']) || empty($this->attachment['height'])) {
    		return;
    	}
    
    	foreach ($this->imageSizes as $imageSize) {
    		// ...
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
        // Get the path to the WordPress upload directory
        $uploadDir = wp_upload_dir()['basedir'] . '/';

        // Get the attachment name
        $attachedFile = get_post_meta($this->attachment['id'], '_wp_attached_file', true);
        $attachment = pathinfo($attachedFile)['filename'];
        $croppedAttachment = $attachment . '-' . $imageSize['width'] . 'x' . $imageSize['height'];

        // Add the image size to the the name of the attachment
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
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Calculate the all of the positions necessary to crop the image and crop it.
     *
     * @param $imageSize
     * @param $imageFilePath
     * @return $this
     */
    private function cropImage($imageSize, $imageFilePath)
    {
        // Gather all dimension
        $dimensions = ['x' => [], 'y' => []];
        $directions = ['x' => 'width', 'y' => 'height'];

        // Define the correction the image needs to keep the same ratio after the cropping has taken place
        $cropCorrection = [
            'x' => $imageSize['ratio'] / $this->attachment['ratio'],
            'y' => $this->attachment['ratio'] / $imageSize['ratio']
        ];

        // Check all the cropping values
        foreach ($dimensions as $axis => $dimension) {

            // Get the center position
            $dimensions[$axis]['center'] = $this->focusPoint[$axis] / 100 * $this->attachment[$directions[$axis]];
            // Get the starting position and let's correct the crop ratio
            $dimensions[$axis]['start'] = $dimensions[$axis]['center'] - $this->attachment[$directions[$axis]] * $cropCorrection[$axis] / 2;
            // Get the ending position and let's correct the crop ratio
            $dimensions[$axis]['end'] = $dimensions[$axis]['center'] + $this->attachment[$directions[$axis]] * $cropCorrection[$axis] / 2;

            // Is the start position lower than 0? That's not possible so let's correct it
            if ($dimensions[$axis]['start'] < 0) {
                // Adjust the ending, but don't make it higher than the image itself
                $dimensions[$axis]['end'] = min($dimensions[$axis]['end'] - $dimensions[$axis]['start'],
                    $this->attachment[$directions[$axis]]);
                // Adjust the start, but don't make it lower than 0
                $dimensions[$axis]['start'] = max($dimensions[$axis]['start'] - $dimensions[$axis]['start'], 0);
            }

            // Is the start position higher than the total image size? That's not possible so let's correct it
            if ($dimensions[$axis]['end'] > $this->attachment[$directions[$axis]]) {
                // Adjust the start, but don't make it lower than 0
                $dimensions[$axis]['start'] = max($dimensions[$axis]['start'] + $this->attachment[$directions[$axis]] - $dimensions[$axis]['end'],
                    0);
                // Adjust the ending, but don't make it higher than the image itself
                $dimensions[$axis]['end'] = min($dimensions[$axis]['end'] + $this->attachment[$directions[$axis]] - $dimensions[$axis]['end'],
                    $this->attachment[$directions[$axis]]);
            }
        }

        // Excecute the WordPress image crop function
        wp_crop_image($this->attachment['id'],
            $dimensions['x']['start'],
            $dimensions['y']['start'],
            $dimensions['x']['end'] - $dimensions['x']['start'],
            $dimensions['y']['end'] - $dimensions['y']['start'],
            $imageSize['width'],
            $imageSize['height'],
            false,
            $imageFilePath
        );

        return $this;
    }
}
