<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    /**
     * Menampilkan daftar transaksi seller yang login dengan status SUCCESS
     * Beserta daftar order items
     */
    public function index()
    {
        $sellerId = Auth::id(); // Ambil ID seller yang sedang login
    
        $orders = Order::where('seller_id', $sellerId)
            ->whereIn('order_status', ['PAID', 'COMPLETED']) // Ambil order dengan status PAID atau COMPLETED
            ->with(['orderItems.product', 'customer']) // Ambil order items & customer
            ->orderByRaw("FIELD(order_status, 'PAID', 'COMPLETED'), created_at DESC") // Urutkan PAID dulu, lalu COMPLETED berdasarkan waktu terbaru
            ->get();
    
        // Ubah format customer_id menjadi customerName
        $orders = $orders->map(function ($order) {
            return [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'customerName' => $order->customer ? $order->customer->name : 'Unknown',
                'seller_id' => $order->seller_id,
                'order_status' => $order->order_status,
                'total_amount' => $order->total_amount,
                'table_number' => $order->table_number,
                'estimated_delivery_time' => $order->estimated_delivery_time,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'order_items' => $order->orderItems
            ];
        });
    
        return response()->json([
            'status' => true,
            'message' => 'List of successful transactions',
            'transactions' => $orders,
        ], 200);
    }
    

    /**
     * Menampilkan detail transaksi tertentu beserta order items
     */
    public function show($id)
    {
        $sellerId = Auth::id();

        $order = Order::where('id', $id)
            ->where('seller_id', $sellerId)
            ->where('order_status', 'PAID') // Hanya transaksi sukses
            ->with(['orderItems.product', 'customer'])
            ->first();

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Transaction not found or not successful'
            ], 404);
        }

        // Ubah customer_id menjadi customerName
        $orderData = [
            'id' => $order->id,
            'order_id' => $order->order_id,
            'customerName' => $order->customer ? $order->customer->name : 'Unknown',
            'seller_id' => $order->seller_id,
            'order_status' => $order->order_status,
            'total_amount' => $order->total_amount,
            'table_number' => $order->table_number,
            'estimated_delivery_time' => $order->estimated_delivery_time,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'order_items' => $order->orderItems
        ];

        return response()->json([
            'status' => true,
            'message' => 'Transaction details',
            'transaction' => $orderData,
        ], 200);
    }

    /**
 * Update order_status menjadi COMPLETED
 */
    public function markAsCompleted($id)
    {
        $sellerId = Auth::id(); // Ambil ID seller yang sedang login

        // Cari order berdasarkan ID dan seller yang login
        $order = Order::where('id', $id)
            ->where('seller_id', $sellerId)
            ->where('order_status', 'PAID') // Hanya bisa update jika statusnya sudah PAID
            ->first();

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found or cannot be completed'
            ], 404);
        }

        // Update order_status menjadi COMPLETED
        $order->update(['order_status' => 'COMPLETED']);

        return response()->json([
            'status' => true,
            'message' => 'Order has been marked as COMPLETED',
            'order' => $order
        ], 200);
    }

    public function getTransactionSummary()
{
    $sellerId = Auth::id(); // Ambil ID seller yang sedang login
    $today = now()->toDateString(); // Ambil tanggal hari ini (format: YYYY-MM-DD)

    // Ambil semua transaksi (tanpa filter tanggal) untuk total keseluruhan
    $allOrders = Order::where('seller_id', $sellerId)
        ->whereIn('order_status', ['PAID', 'COMPLETED'])
        ->with(['orderItems.product', 'customer'])
        ->get();

    // Ambil transaksi HANYA untuk hari ini untuk ringkasan produk
    $todayOrders = Order::where('seller_id', $sellerId)
        ->whereIn('order_status', ['PAID', 'COMPLETED'])
        ->whereDate('created_at', $today) // Filter hanya transaksi hari ini
        ->with(['orderItems.product', 'customer'])
        ->get();

    $productSummary = []; // Untuk menyimpan ringkasan item berdasarkan produk (HANYA hari ini)
    $totalAllQuantity = 0; // Total semua item (semua transaksi)
    $totalAllAmount = 0; // Total semua harga (semua transaksi)

    // Hitung total semua item & harga dari SEMUA transaksi
    foreach ($allOrders as $order) {
        foreach ($order->orderItems as $item) {
            $totalAllQuantity += $item->quantity;
            $totalAllAmount += $item->quantity * $item->price;
        }
    }

    // Hitung product summary HANYA untuk transaksi hari ini
    foreach ($todayOrders as $order) {
        foreach ($order->orderItems as $item) {
            $productName = $item->product->name;
            
            // Jika produk belum ada di summary, inisialisasi
            if (!isset($productSummary[$productName])) {
                $productSummary[$productName] = [
                    'product_name' => $productName,
                    'total_quantity' => 0,
                    'total_price' => 0,
                    'unit_price' => $item->price, // Harga satuan produk
                ];
            }

            // Tambahkan quantity dan total harga produk
            $productSummary[$productName]['total_quantity'] += $item->quantity;
            $productSummary[$productName]['total_price'] += $item->quantity * $item->price;
        }
    }

    // Ubah array ke bentuk numerik (tanpa key string)
    $productSummary = array_values($productSummary);

    return response()->json([
        'status' => true,
        'message' => 'Transaction summary',
        'total_all_quantity' => $totalAllQuantity, // Total semua item dari semua transaksi
        'total_all_amount' => number_format($totalAllAmount, 2), // Total harga dari semua transaksi
        'product_summary' => $productSummary, // List total item per produk (HANYA hari ini)
    ], 200);
}


    public function getTransactionSummaryByDate(Request $request)
    {
        $sellerId = Auth::id(); // Ambil ID seller yang sedang login

        // Ambil parameter tanggal dari request
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = Order::where('seller_id', $sellerId)
            ->whereIn('order_status', ['PAID', 'COMPLETED'])
            ->with(['orderItems.product', 'customer']);

        // Filter berdasarkan tanggal jika diberikan
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $orders = $query->get();

        $productSummary = []; // Untuk menyimpan ringkasan item berdasarkan produk
        $totalAllQuantity = 0; // Total semua item
        $totalAllAmount = 0; // Total semua harga

        foreach ($orders as $order) {
            foreach ($order->orderItems as $item) {
                $productName = $item->product->name;
                
                // Jika produk belum ada di summary, inisialisasi
                if (!isset($productSummary[$productName])) {
                    $productSummary[$productName] = [
                        'product_name' => $productName,
                        'total_quantity' => 0,
                        'total_price' => 0,
                        'unit_price' => $item->price, // Harga satuan produk
                    ];
                }

                // Tambahkan quantity dan total harga produk
                $productSummary[$productName]['total_quantity'] += $item->quantity;
                $productSummary[$productName]['total_price'] += $item->quantity * $item->price;

                // Tambahkan ke total semua item & semua harga
                $totalAllQuantity += $item->quantity;
                $totalAllAmount += $item->quantity * $item->price;
            }
        }

        // Ubah array ke bentuk numerik (tanpa key string)
        $productSummary = array_values($productSummary);

        return response()->json([
            'status' => true,
            'message' => 'Transaction summary by date',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_all_quantity' => $totalAllQuantity, // Total semua item
            'total_all_amount' => number_format($totalAllAmount, 2), // Total harga semua item
            'product_summary' => $productSummary, // List total item per produk
        ], 200);
    }

}
