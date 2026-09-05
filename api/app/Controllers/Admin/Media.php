<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class Media extends BaseController
{
    /**
     * Accepts multipart/form-data with field "file". Saves under public/uploads/YYYY/MM/.
     * Returns { url, filename, size, mime }.
     */
    public function upload()
    {
        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->validationError(['file' => 'required (multipart field "file")']);
        }
        $allowedImages = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
        $allowedVideos = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
        $mime          = $file->getMimeType();
        $isImage       = in_array($mime, $allowedImages, true);
        $isVideo       = in_array($mime, $allowedVideos, true);

        if (!$isImage && !$isVideo) {
            return $this->fail('UNSUPPORTED_TYPE',
                'Only JPEG/PNG/WEBP/GIF/AVIF images and MP4/WEBM/OGG/MOV videos are allowed', null, 415);
        }
        // Videos can be much larger than images — bump the cap to 50 MB for videos.
        if ($isVideo && $file->getSize() > 50 * 1024 * 1024) {
            return $this->fail('TOO_LARGE', 'Video exceeds 50 MB. Please compress before uploading.', null, 413);
        }
        $subdir = date('Y') . DIRECTORY_SEPARATOR . date('m');
        $dest   = FCPATH . 'uploads' . DIRECTORY_SEPARATOR . $subdir;
        if (!is_dir($dest)) mkdir($dest, 0775, true);
        $name   = $file->getRandomName();
        $file->move($dest, $name);

        $base = rtrim((string) env('upload.public_url', 'http://localhost:8080/uploads/'), '/') . '/';
        $url  = $base . str_replace(DIRECTORY_SEPARATOR, '/', $subdir) . '/' . $name;

        return $this->created([
            'url'        => $url,
            'filename'   => $name,
            'size'       => filesize($dest . DIRECTORY_SEPARATOR . $name),
            'mime'       => $file->getClientMimeType(),
            'media_type' => $isVideo ? 'video' : 'image',
        ]);
    }
}
