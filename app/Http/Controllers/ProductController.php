<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage; // Tambahkan ini
use App\Models\Category;



class ProductController extends Controller
{
    // Menampilkan semua produk
    public function index()
    {
        $products = Product::with(['seller', 'category'])->get()->map(function ($product) {
            // Menambahkan URL lengkap untuk gambar produk
            $product->image = $product->image ? url('storage/' . $product->image) : null;

            // Menambahkan URL lengkap untuk gambar penjual
            if ($product->seller) {
                $product->seller->image = $product->seller->image ? url('storage/' . $product->seller->image) : null;
            }

            // Menambahkan URL lengkap untuk gambar kategori
            if ($product->category) {
                $product->category->image = $product->category->image ? url('storage/' . $product->category->image) : null;
            }

            return $product;
        });

        

        return response()->json([
            'status' => true,
            'data' => $products
        ]);
    }

    // Menampilkan produk berdasarkan categoryId
    public function getProductsByCategory($categoryId)
    {
    // Validasi apakah categoryId ada di database
    $category = Category::find($categoryId);

    if (!$category) {
        return response()->json([
            'status' => false,
            'message' => 'Kategori tidak ditemukan'
        ], 404);
    }

    // Mengambil produk berdasarkan categoryId
    $products = Product::with(['seller', 'category'])
        ->where('category_id', $categoryId)
        ->get()
        ->map(function ($product) {
            // Menambahkan URL lengkap untuk gambar produk
            $product->image = $product->image ? url('storage/' . $product->image) : null;

            // Menambahkan URL lengkap untuk gambar penjual
            if ($product->seller) {
                $product->seller->image = $product->seller->image ? url('storage/' . $product->seller->image) : null;
            }

            // Menambahkan URL lengkap untuk gambar kategori
            if ($product->category) {
                $product->category->image = $product->category->image ? url('storage/' . $product->category->image) : null;
            }

            return $product;
        });

    return response()->json([
        'status' => true,
        'data' => $products
    ]);
}


    //menampilkan produk seller login saja
    public function indexSellerProducts()
    {
        // Ambil user yang sedang login
        $user = auth()->user();

        // Filter produk berdasarkan seller_id dari token
        if ($user->role === 'seller') {
            $products = Product::with(['seller', 'category'])
                        ->where('seller_id', $user->id)
                        ->get();

            // Buat response dengan URL lengkap untuk image
            $products->transform(function ($product) {
                $product->image = $product->image ? url("storage/{$product->image}") : null;
                $product->seller->image = $product->seller->image ? url("storage/{$product->seller->image}") : null;
                $product->category->image = $product->category->image ? url("storage/{$product->category->image}") : null;
                return $product;
            });

            return response()->json([
                'status' => true,
                'data' => $products
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }
    }



    // Menambahkan produk baru
    public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'category_id' => 'required|exists:categories,id',
        'name' => 'required|string',
        'description' => 'required|string',
        'price' => 'required|numeric',
        'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'stock' => 'required|integer|min:0'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'Validation Error',
            'errors' => $validator->errors()
        ], 422);
    }

    $imagePath = null;
    if ($request->hasFile('image')) {
        $imagePath = $request->file('image')->store('public/product-images');
        $imagePath = str_replace('public/', '', $imagePath);
    }

    $product = Product::create([
        'seller_id' => auth()->id(),
        'category_id' => $request->category_id,
        'name' => $request->name,
        'description' => $request->description,
        'price' => $request->price,
        'image' => $imagePath,
        'stock' => $request->stock
    ]);

    // Menambahkan URL gambar penuh untuk respons
    $product->image = $product->image ? url('storage/' . $product->image) : null;

    return response()->json([
        'status' => true,
        'message' => 'Product created successfully',
        'data' => $product
    ], 201);
}


    // Mengupdate produk
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string',
            'description' => 'required|string',
            'price' => 'required|numeric',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'stock' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Handle image upload jika ada image baru
            if ($request->hasFile('image')) {
                // Hapus image lama jika ada
                if ($product->image) {
                    Storage::delete('public/products/' . $product->image);
                }

                // Upload image baru
                $imageName = time() . '.' . $request->image->extension();
                $request->image->storeAs('public/products', $imageName);
                $product->image = $imageName;
            }

            // Update data produk
            $product->update([
                'category_id' => $request->category_id,
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'stock' => $request->stock
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);

            // Cek apakah user yang login adalah pemilik produk
            if (auth()->id() !== $product->seller_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized to delete this product'
                ], 403);
            }

            // Hapus gambar jika ada
            if ($product->image) {
                $imagePath = str_replace('storage/', '', $product->image);
                if (Storage::exists('public/' . $imagePath)) {
                    Storage::delete('public/' . $imagePath);
                }
            }

            // Hapus produk
            $product->delete();

            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete product: ' . $e->getMessage()
            ], 500);
        }
    }

    // Mencari produk berdasarkan kata kunci
public function search(Request $request)
{
    $keyword = $request->query('keyword');

    if (!$keyword) {
        return response()->json([
            'status' => false,
            'message' => 'Keyword is required'
        ], 400);
    }

    $products = Product::with(['seller', 'category'])
        ->where('name', 'LIKE', "%{$keyword}%")
        ->orWhere('description', 'LIKE', "%{$keyword}%")
        ->get()
        ->map(function ($product) {
            // Menambahkan URL lengkap untuk gambar produk
            $product->image = $product->image ? url('storage/' . $product->image) : null;

            // Menambahkan URL lengkap untuk gambar penjual
            if ($product->seller) {
                $product->seller->image = $product->seller->image ? url('storage/' . $product->seller->image) : null;
            }

            // Menambahkan URL lengkap untuk gambar kategori
            if ($product->category) {
                $product->category->image = $product->category->image ? url('storage/' . $product->category->image) : null;
            }

            return $product;
        });

    return response()->json([
        'status' => true,
        'data' => $products
    ]);
}

public function getProductsByRating(Request $request)
{
    $threshold = $request->input('rating', 4.5); // Default threshold rating adalah 4.5

    $products = Product::with(['seller', 'category'])
        ->whereIn('id', function ($query) use ($threshold) {
            $query->select('product_id')
                  ->from('reviews')
                  ->groupBy('product_id')
                  ->havingRaw('AVG(rating) >= ?', [$threshold]);
        })
        ->get()
        ->map(function ($product) {
            // Menambahkan URL lengkap untuk gambar produk
            $product->image = $product->image ? url('storage/' . $product->image) : null;

            // Menambahkan URL lengkap untuk gambar penjual
            if ($product->seller) {
                $product->seller->image = $product->seller->image ? url('storage/' . $product->seller->image) : null;
            }

            // Menambahkan URL lengkap untuk gambar kategori
            if ($product->category) {
                $product->category->image = $product->category->image ? url('storage/' . $product->category->image) : null;
            }

            // Menambahkan rata-rata rating ke dalam respons
            $product->average_rating = $product->reviews()->avg('rating');
            return $product;
        });

    return response()->json([
        'status' => true,
        'data' => $products,
    ]);
}


}
