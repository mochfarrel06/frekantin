<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    // Menampilkan semua review
    public function index()
    {
        $reviews = Review::with('product', 'customer')->get();
        return response()->json([
            'status' => true,
            'data' => $reviews,
        ]);
    }

    // Menambahkan review baru
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:users,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Handle image upload
        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('public/review-images');
            $imagePath = str_replace('public/', '', $imagePath);
        }

        $review = Review::create([
            'product_id' => $request->product_id,
            'customer_id' => $request->customer_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'image' => $imagePath,
            'review_date' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Review created successfully',
            'data' => $review,
        ], 201);
    }

    public function getReviewsByProduct($productId)
    {
        $reviews = Review::where('product_id', $productId)->with('customer')->get();

        if ($reviews->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No reviews found for this product.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $reviews,
        ], 200);
    }

    public function getAverageRatingByProduct($productId)
    {
        // Mengambil semua review untuk produk tertentu
        $reviews = Review::where('product_id', $productId)->get();

        // Menghitung rata-rata rating
        if ($reviews->count() > 0) {
            $averageRating = $reviews->avg('rating');
        } else {
            // Jika tidak ada review, default ke 0
            $averageRating = 0;
        }

        // Mengembalikan respon dengan rata-rata rating
        return response()->json([
            'averageRating' => $averageRating
        ]);
    }
    // Mengupdate review
    public function update(Request $request, $id)
    {
        $review = Review::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'sometimes|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            // Hapus gambar lama jika ada
            if ($review->image) {
                Storage::disk('public')->delete($review->image);
            }
            $imagePath = $request->file('image')->store('public/review-images');
            $review->image = str_replace('public/', '', $imagePath);
        }

        $review->update($request->only(['rating', 'comment', 'image']));

        return response()->json([
            'status' => true,
            'message' => 'Review updated successfully',
            'data' => $review,
        ], 200);
    }

    // Menghapus review
    public function destroy($id)
    {
        $review = Review::findOrFail($id);

        if ($review->image) {
            Storage::disk('public')->delete($review->image);
        }

        $review->delete();

        return response()->json([
            'status' => true,
            'message' => 'Review deleted successfully',
        ]);
    }
}
