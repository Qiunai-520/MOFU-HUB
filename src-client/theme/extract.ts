import { hexFromArgb, sourceColorFromImage } from '@material/material-color-utilities';

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error('图片加载失败'));
    image.src = src;
  });
}

/**
 * 从图片中提取源色（HCT quantize）。
 */
export async function extractSourceColor(file: File): Promise<string> {
  const url = URL.createObjectURL(file);

  try {
    const image = await loadImage(url);
    const argb = await sourceColorFromImage(image);

    return hexFromArgb(argb).toUpperCase();
  } finally {
    URL.revokeObjectURL(url);
  }
}
