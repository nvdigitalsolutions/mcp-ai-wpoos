/**
 * Mirrors the Funiq PWA frontend types — shared between admin SPA and REST API.
 */

export interface Brand {
  id: number;
  name: string;
}

export interface Color {
  id: number;
  name: string;
  hexCode?: string;
}

export interface Category {
  id: number;
  name: string;
  image: string;
  productsCount: number;
}

export interface Status {
  id: number;
  name: string;
}

export interface Promotion {
  id: number;
  title: string;
  description: string;
  startDate: string;
  endDate: string;
  active: boolean;
}

export interface Promocode {
  id: number;
  name: string;
  title: string;
  code: string;
  discount: number;
  expiresAt: string;
  isActive: boolean;
  logo: string;
}

export interface Product {
  id: number;
  name: string;
  price: number;
  oldPrice: number | null;
  description: string;
  category: Category | null;
  brand: Brand | null;
  colors: Color[];
  width: number;
  height: number;
  depth: number;
  rating: number | null;
  isBestseller: boolean;
  isFeatured: boolean;
  /** PWA-facing — absolute URL to the featured image. */
  image: string;
  /** PWA-facing — absolute URLs for gallery images. */
  images: string[];
  /** Admin-facing — WordPress attachment ID for the featured image. */
  imageId: number | null;
  /** Admin-facing — WordPress attachment IDs for gallery images. */
  imagesIds: number[];
  statuses: Status[];
  promotion: Promotion | null;
  createdAt: string;
  updatedAt: string;
}

export interface Banner {
  image: string;
  promotion: Promotion | null;
}

export interface CarouselItem {
  image: string;
  promotion: Promotion | null;
}

export interface Carousel {
  carousel: CarouselItem[];
}

/** Payload-paginated response envelope. */
export interface PaginatedResponse<T> {
  docs: T[];
  totalDocs: number;
  limit: number;
  totalPages: number;
  page: number;
  hasNextPage: boolean;
  hasPrevPage: boolean;
}

/** All collection types supported by the admin. */
export type CollectionSlug =
  | 'products'
  | 'categories'
  | 'brands'
  | 'colors'
  | 'statuses'
  | 'promotions'
  | 'promocodes';
