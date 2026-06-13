<form method="POST" action="{{ route('orders.wz.create', $order) }}">
    @csrf
    <button class="button" type="submit">Utworz WZ</button>
</form>
