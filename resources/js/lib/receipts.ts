export const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'doc', 'docx'];

/**
 * image/* lets iPhones offer the photo library as well as files. HEIC is left out on purpose: when a page doesn't
 * ask for it, iOS converts photos to JPEG, which every browser can show. HEIC files are still accepted.
 */
export const ACCEPT = [...EXTENSIONS.filter((extension) => !['heic', 'heif'].includes(extension)).map((extension) => `.${extension}`), 'image/*'].join(',');

export const MAX_BYTES = 20 * 1024 * 1024;

/** Checks type and size before uploading. Returns a message for the first problem, or null. */
export function checkFiles(files: File[]): string | null {
    for (const file of files) {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!EXTENSIONS.includes(extension)) {
            return `${file.name} isn’t a PDF, photo or Word document.`;
        }

        if (file.size > MAX_BYTES) {
            return `${file.name} is larger than 20 MB.`;
        }
    }

    return null;
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
