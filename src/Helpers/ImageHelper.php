<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

use Intervention\Image\Laravel\Facades\Image;

class ImageHelper
{
    public static function storeImageInPublicDirectory($image, $directory, $width = null, $hight = null)
    {
        try{
        ini_set('memory_limit', '2048M'); // Adjust as needed

        // dd($width);
        $filename = $image->getClientOriginalName();
        $path = $directory;
        // $path = public_path($directory);
        // dd($path);
        // Process the image using Intervention Image (optional)
        if ($width == null && $hight == null) {
            $processedImage = Image::read($image);
        } elseif ($width != null && $hight != null) {
            $processedImage = Image::read($image)
                ->resize($width, $hight); // Encode as WebP with 80% quality (adjust as needed)

        } else {
            $processedImage = Image::read($image)
                ->resize(800, 500, function ($constraint) { // Set width and scale height proportionally
                    $constraint->aspectRatio();
                    $constraint->upsize();
                }); // Encode as WebP with 80% quality (adjust as needed)
        }

        // imagedestroy($processedImage->getCore()); // releases memory
        // Store the processed image in the public directory
        // dd($processedImage);
        $filename = time() . '.' . $image->getClientOriginalExtension();
        Storage::disk('public')->put($path . '/' . $filename,
            $processedImage->encodeByExtension($image->getClientOriginalExtension(), quality: 80)
        );
        // Storage::disk('public')->put($path . '/' . $filename, $processedImage);
        return $path . '/' . $filename; // Return the full path with name

        }
        catch (\Exception $e) {
            // handle memory exhaustion
            dd($e);
        }
    }
    public static function removeImageInPublicDirectory($image)
    {
        try {
            Storage::disk('public')->delete($image);
        } catch (\Throwable $th) {
            //throw $th;
        }
    }
}
