/** Public booking page — no auth required. */
export function BookingPage() {
  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center">
      <div className="max-w-md w-full bg-white rounded-lg shadow-lg p-8 text-center">
        <h1 className="text-2xl font-bold text-gray-900 mb-4">Book an Appointment</h1>
        <p className="text-gray-500">Select a service, pick a time, and confirm your booking.</p>
        <div className="mt-6 p-4 bg-gray-100 rounded-lg">
          <p className="text-sm text-gray-600">Booking portal coming soon.</p>
        </div>
      </div>
    </div>
  );
}
