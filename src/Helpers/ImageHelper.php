<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class ImageHelper
{
    /**
     * Store an image in the public disk with optional resizing.
     *
     * @param \Illuminate\Http\UploadedFile $image
     * @param string $directory
     * @param int|null $width
     * @param int|null $height
     * @return string|null Path to the stored image or null on failure
     */
    public static function storeImageInPublicDirectory($image, $directory, $width = null, $height = null)
    {
        try {
            ini_set('memory_limit', '2048M'); // Temporarily increase memory limit

            $filename = time() . '.' . $image->getClientOriginalExtension();
            $path = "uploads/$directory";

            // Process image with Intervention Image
            $processedImage = Image::read($image);
            if ($width !== null && $height !== null) {
                $processedImage->resize($width, $height);
            } elseif ($width !== null || $height !== null) {
                $processedImage->resize($width ?: 800, $height ?: 500, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

            // Store the processed image
            Storage::disk('public')->put(
                "$path/$filename",
                $processedImage->encodeByExtension($image->getClientOriginalExtension(), quality: 80)
            );

            return "$path/$filename";
        } catch (\Exception $e) {
            \Log::error("Failed to store image: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Remove an image from the public disk.
     *
     * @param string $image Path to the image
     * @return bool Success status
     */
    public static function removeImageInPublicDirectory($image)
    {
        try {
            if (Storage::disk('public')->exists($image)) {
                Storage::disk('public')->delete($image);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            \Log::error("Failed to remove image '$image': {$e->getMessage()}");
            return false;
        }
    }
}