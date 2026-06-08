/**
 * Config-driven collection definitions — mirrors Payload schema.
 *
 * Each collection defines its field set so ListView and EditForm can
 * render generic CRUD UI without per-collection code.
 */
import type { CollectionSlug } from '../types';

export interface FieldConfig {
  name: string;
  type: 'text' | 'number' | 'textarea' | 'checkbox' | 'date' | 'upload' | 'relationship' | 'array';
  label: string;
  required?: boolean;
  /** For relationship fields — the related collection slug. */
  relation?: string;
  /** True for hasMany relationships. */
  hasMany?: boolean;
  /** For number fields. */
  min?: number;
  max?: number;
  step?: number;
}

export interface CollectionConfig {
  slug: CollectionSlug;
  label: string;
  pluralLabel: string;
  type: 'collection' | 'global';
  defaultColumns: string[];
  fields: FieldConfig[];
}

export const collections: CollectionConfig[] = [
  {
    slug: 'products',
    label: 'Product',
    pluralLabel: 'Products',
    type: 'collection',
    defaultColumns: ['name', 'price', 'category', 'brand'],
    fields: [
      { name: 'name', type: 'text', label: 'Product Name', required: true },
      { name: 'price', type: 'number', label: 'Price', required: true, min: 0, step: 0.01 },
      { name: 'oldPrice', type: 'number', label: 'Old Price', min: 0, step: 0.01 },
      { name: 'category', type: 'relationship', label: 'Category', relation: 'categories', required: true },
      { name: 'brand', type: 'relationship', label: 'Brand', relation: 'brands', required: true },
      { name: 'colors', type: 'relationship', label: 'Colors', relation: 'colors', hasMany: true },
      { name: 'description', type: 'textarea', label: 'Description', required: true },
      { name: 'width', type: 'number', label: 'Width (cm)', min: 0, step: 0.1 },
      { name: 'height', type: 'number', label: 'Height (cm)', min: 0, step: 0.1 },
      { name: 'depth', type: 'number', label: 'Depth (cm)', min: 0, step: 0.1 },
      { name: 'rating', type: 'number', label: 'Rating', min: 0, max: 5, step: 0.1 },
      { name: 'isBestseller', type: 'checkbox', label: 'Bestseller' },
      { name: 'isFeatured', type: 'checkbox', label: 'Featured' },
      { name: 'statuses', type: 'relationship', label: 'Statuses', relation: 'statuses', hasMany: true },
      { name: 'promotion', type: 'relationship', label: 'Promotion', relation: 'promotions' },
      { name: 'image', type: 'upload', label: 'Main Image', required: true },
      { name: 'images', type: 'array', label: 'Additional Images' },
    ],
  },
  {
    slug: 'categories',
    label: 'Category',
    pluralLabel: 'Categories',
    type: 'collection',
    defaultColumns: ['name', 'productsCount'],
    fields: [
      { name: 'name', type: 'text', label: 'Category Name', required: true },
      { name: 'image', type: 'upload', label: 'Category Image' },
    ],
  },
  {
    slug: 'brands',
    label: 'Brand',
    pluralLabel: 'Brands',
    type: 'collection',
    defaultColumns: ['name'],
    fields: [
      { name: 'name', type: 'text', label: 'Brand Name', required: true },
    ],
  },
  {
    slug: 'colors',
    label: 'Color',
    pluralLabel: 'Colors',
    type: 'collection',
    defaultColumns: ['name', 'hexCode'],
    fields: [
      { name: 'name', type: 'text', label: 'Color Name', required: true },
      { name: 'hexCode', type: 'text', label: 'Hex Code' },
    ],
  },
  {
    slug: 'statuses',
    label: 'Status',
    pluralLabel: 'Statuses',
    type: 'collection',
    defaultColumns: ['name'],
    fields: [
      { name: 'name', type: 'text', label: 'Status Name', required: true },
    ],
  },
  {
    slug: 'promotions',
    label: 'Promotion',
    pluralLabel: 'Promotions',
    type: 'collection',
    defaultColumns: ['title', 'active', 'startDate', 'endDate'],
    fields: [
      { name: 'title', type: 'text', label: 'Title', required: true },
      { name: 'description', type: 'textarea', label: 'Description' },
      { name: 'startDate', type: 'date', label: 'Start Date' },
      { name: 'endDate', type: 'date', label: 'End Date' },
      { name: 'active', type: 'checkbox', label: 'Active' },
    ],
  },
  {
    slug: 'promocodes',
    label: 'Promocode',
    pluralLabel: 'Promocodes',
    type: 'collection',
    defaultColumns: ['code', 'discount', 'isActive', 'expiresAt'],
    fields: [
      { name: 'code', type: 'text', label: 'Code', required: true },
      { name: 'discount', type: 'number', label: 'Discount (%)', min: 0, max: 100 },
      { name: 'name', type: 'text', label: 'Name' },
      { name: 'title', type: 'text', label: 'Title' },
      { name: 'expiresAt', type: 'date', label: 'Expires At' },
      { name: 'isActive', type: 'checkbox', label: 'Active' },
      { name: 'logo', type: 'upload', label: 'Logo' },
    ],
  },
];
